package com.medicalplus.nativefiles

import android.util.Log
import java.io.BufferedOutputStream
import java.io.File
import java.io.RandomAccessFile
import java.net.ServerSocket
import java.net.Socket
import java.net.URLDecoder
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean
import kotlin.concurrent.thread

/**
 * A minimal HTTP/1.1 file server bound to loopback, used only to play
 * device-local media in the WebView.
 *
 * Why the app needs its own socket for this: every request the WebView makes
 * to the app's own origin is answered by the embedded PHP runtime through the
 * native bridge, and that bridge cannot deliver a binary body at all — the
 * response arrives with correct headers and zero bytes, so <video> retries
 * forever. Base64-over-JSON does get through, but it means downloading the
 * WHOLE file (one PHP boot per chunk, ~700ms each) into memory before the
 * first frame: fine for a 2MB clip, hopeless for 50MB and impossible for
 * 500MB.
 *
 * Serving the file from a plain socket sidesteps both problems: the bytes
 * never touch the bridge or PHP, Range requests are answered from disk, so
 * playback starts immediately, seeking works, and memory stays at one 64KB
 * buffer no matter how large the file is.
 *
 * Scope is deliberately tiny: loopback-only bind (unreachable from other
 * devices), GET/HEAD only, and paths are opaque tokens that map to an
 * explicitly registered file — a request cannot name an arbitrary path on
 * disk.
 */
object LocalMediaServer {

    private const val TAG = "LocalMediaServer"

    private val running = AtomicBoolean(false)
    private var serverSocket: ServerSocket? = null
    private var port: Int = 0

    /** token -> absolute file path. Only registered files are reachable. */
    private val registry = ConcurrentHashMap<String, String>()

    private val workers = Executors.newFixedThreadPool(4)

    /**
     * Register [absolutePath] under [token] and make sure the server is up.
     * Returns the loopback URL the WebView should use, or null if the file is
     * missing or the server could not start.
     */
    @Synchronized
    fun serve(token: String, absolutePath: String): String? {
        val file = File(absolutePath)
        if (!file.isFile || !file.canRead()) {
            Log.w(TAG, "refusing to serve missing/unreadable file: $absolutePath")
            return null
        }

        if (!start()) return null

        registry[token] = file.absolutePath
        return "http://127.0.0.1:$port/media/$token"
    }

    @Synchronized
    private fun start(): Boolean {
        if (running.get() && serverSocket?.isClosed == false) return true

        return try {
            // Port 0 = let the OS pick a free one; a fixed port would collide
            // with whatever else the device happens to be running.
            val socket = ServerSocket(0, 8, java.net.InetAddress.getByName("127.0.0.1"))
            serverSocket = socket
            port = socket.localPort
            running.set(true)

            thread(isDaemon = true, name = "local-media-server") {
                while (running.get() && !socket.isClosed) {
                    try {
                        val client = socket.accept()
                        workers.execute { handle(client) }
                    } catch (e: Throwable) {
                        if (running.get()) Log.w(TAG, "accept failed: ${e.message}")
                    }
                }
            }

            Log.i(TAG, "listening on 127.0.0.1:$port")
            true
        } catch (e: Throwable) {
            Log.e(TAG, "could not start: ${e.message}")
            running.set(false)
            false
        }
    }

    private fun handle(client: Socket) {
        client.use { sock ->
            try {
                sock.soTimeout = 15_000
                val input = sock.getInputStream().bufferedReader()

                val requestLine = input.readLine() ?: return
                val parts = requestLine.split(' ')
                if (parts.size < 2) return
                val method = parts[0].uppercase()
                val path = URLDecoder.decode(parts[1], "UTF-8")

                var rangeHeader: String? = null
                while (true) {
                    val line = input.readLine() ?: break
                    if (line.isBlank()) break
                    if (line.startsWith("Range:", ignoreCase = true)) {
                        rangeHeader = line.substringAfter(':').trim()
                    }
                }

                if (method != "GET" && method != "HEAD") {
                    writeStatusOnly(sock, 405, "Method Not Allowed")
                    return
                }

                val token = path.removePrefix("/media/").substringBefore('?')
                val filePath = registry[token]
                if (filePath == null) {
                    writeStatusOnly(sock, 404, "Not Found")
                    return
                }

                val file = File(filePath)
                if (!file.isFile) {
                    writeStatusOnly(sock, 404, "Not Found")
                    return
                }

                serveFile(sock, file, rangeHeader, headOnly = method == "HEAD")
            } catch (e: Throwable) {
                Log.w(TAG, "request failed: ${e.message}")
            }
        }
    }

    private fun serveFile(sock: Socket, file: File, rangeHeader: String?, headOnly: Boolean) {
        val length = file.length()
        var start = 0L
        var end = length - 1
        var partial = false

        // "bytes=START-END", either side optional. This is the whole reason the
        // server exists — the player asks for exactly the window it needs and
        // seeks by issuing a new range, instead of pulling the entire file.
        if (rangeHeader != null && rangeHeader.startsWith("bytes=")) {
            val spec = rangeHeader.removePrefix("bytes=").trim()
            val dash = spec.indexOf('-')
            if (dash >= 0) {
                val fromText = spec.substring(0, dash).trim()
                val toText = spec.substring(dash + 1).trim()
                try {
                    if (fromText.isNotEmpty()) {
                        start = fromText.toLong()
                        if (toText.isNotEmpty()) end = minOf(toText.toLong(), length - 1)
                    } else if (toText.isNotEmpty()) {
                        // suffix range: last N bytes
                        val suffix = toText.toLong()
                        start = maxOf(0L, length - suffix)
                    }
                    partial = true
                } catch (_: NumberFormatException) {
                    start = 0; end = length - 1; partial = false
                }
            }
        }

        if (start > end || start >= length) {
            val out = BufferedOutputStream(sock.getOutputStream())
            out.write(
                ("HTTP/1.1 416 Range Not Satisfiable\r\n" +
                    "Content-Range: bytes */$length\r\n" +
                    "Content-Length: 0\r\n" +
                    "Connection: close\r\n\r\n").toByteArray()
            )
            out.flush()
            return
        }

        val count = end - start + 1
        val header = StringBuilder()
        header.append(if (partial) "HTTP/1.1 206 Partial Content\r\n" else "HTTP/1.1 200 OK\r\n")
        header.append("Content-Type: ${mimeFor(file.name)}\r\n")
        header.append("Content-Length: $count\r\n")
        header.append("Accept-Ranges: bytes\r\n")
        if (partial) header.append("Content-Range: bytes $start-$end/$length\r\n")
        // The page is served from the app's own origin, so the media request is
        // cross-origin to this socket.
        header.append("Access-Control-Allow-Origin: *\r\n")
        header.append("Cache-Control: no-store\r\n")
        header.append("Connection: close\r\n\r\n")

        val out = BufferedOutputStream(sock.getOutputStream())
        out.write(header.toString().toByteArray())

        if (headOnly) {
            out.flush()
            return
        }

        RandomAccessFile(file, "r").use { raf ->
            raf.seek(start)
            val buffer = ByteArray(64 * 1024)
            var remaining = count
            while (remaining > 0) {
                val want = minOf(buffer.size.toLong(), remaining).toInt()
                val read = raf.read(buffer, 0, want)
                if (read <= 0) break
                out.write(buffer, 0, read)
                remaining -= read
            }
        }
        out.flush()
    }

    private fun writeStatusOnly(sock: Socket, code: Int, reason: String) {
        val out = BufferedOutputStream(sock.getOutputStream())
        out.write(
            ("HTTP/1.1 $code $reason\r\n" +
                "Content-Length: 0\r\n" +
                "Connection: close\r\n\r\n").toByteArray()
        )
        out.flush()
    }

    private fun mimeFor(name: String): String {
        return when (name.substringAfterLast('.', "").lowercase()) {
            "mp4", "m4v" -> "video/mp4"
            "mov" -> "video/quicktime"
            "webm" -> "video/webm"
            "mkv" -> "video/x-matroska"
            "3gp" -> "video/3gpp"
            "avi" -> "video/x-msvideo"
            "mp3" -> "audio/mpeg"
            "m4a" -> "audio/mp4"
            "wav" -> "audio/wav"
            "jpg", "jpeg" -> "image/jpeg"
            "png" -> "image/png"
            "webp" -> "image/webp"
            "pdf" -> "application/pdf"
            else -> "application/octet-stream"
        }
    }
}
