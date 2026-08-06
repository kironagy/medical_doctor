package com.medicalplus.nativefiles

import android.app.DownloadManager
import android.content.ContentValues
import android.content.Context
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.util.Base64
import android.webkit.MimeTypeMap
import android.widget.Toast
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import java.io.File
import java.io.FileOutputStream

/**
 * Bridge entry points for NativeFiles.* — see nativephp.json for the
 * PHP-visible names.
 *
 * Why this exists at all: the preview's download control used to be a plain
 * <a href target="_blank">. Inside the app's WebView that opens nothing and
 * downloads nothing — tapping it produced no action of any kind. A WebView
 * only downloads when the host registers a DownloadListener, which this app
 * does not, so the save has to happen on the native side.
 */
object NativeFilesFunctions {

    /**
     * Hand a URL to Android's DownloadManager. DownloadManager owns the
     * transfer, the retries and the system progress notification (the one the
     * user can watch in the shade and tap to open), which is exactly the
     * behaviour asked for and is not something the WebView can produce.
     */
    class Save(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val url = parameters["url"] as? String
                ?: return mapOf("success" to false, "error" to "url is required")
            val fileName = sanitize(parameters["file_name"] as? String ?: guessName(url))
            val mime = parameters["mime"] as? String
            val title = parameters["title"] as? String ?: fileName
            val cookie = parameters["cookie"] as? String

            return try {
                val request = DownloadManager.Request(Uri.parse(url)).apply {
                    setTitle(title)
                    setDescription(context.applicationInfo.loadLabel(context.packageManager))
                    // VISIBLE_NOTIFY_COMPLETED keeps the notification up while
                    // downloading AND after it finishes, so the file is one tap
                    // away instead of silently appearing in Downloads.
                    setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
                    setAllowedOverMetered(true)
                    setAllowedOverRoaming(true)
                    if (mime != null) setMimeType(mime)
                    // The embedded server authenticates by session cookie; without
                    // it a device-local URL comes back as the login page instead
                    // of the file.
                    if (!cookie.isNullOrBlank()) addRequestHeader("Cookie", cookie)
                }

                val manager = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
                val id = manager.enqueue(request)

                mapOf("success" to true, "download_id" to id, "file_name" to fileName)
            } catch (e: Throwable) {
                mapOf("success" to false, "error" to (e.message ?: e.toString()))
            }
        }
    }

    /**
     * Write bytes we already hold to the public Downloads folder.
     *
     * DownloadManager cannot be used for a file that only exists inside the
     * app's own embedded server: it runs as a separate system process and
     * would have to re-fetch the URL, which for the app's own local paths it
     * cannot reach. The JS side already has those bytes (it reads them in base64
     * chunks to play local video), so it passes them straight through here.
     */
    class SaveBytes(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val base64 = parameters["data"] as? String
                ?: return mapOf("success" to false, "error" to "data is required")
            val fileName = sanitize(parameters["file_name"] as? String ?: "download")
            val mime = parameters["mime"] as? String ?: guessMime(fileName)

            return try {
                val bytes = Base64.decode(base64, Base64.DEFAULT)

                val savedPath = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    // Scoped storage: MediaStore is the only writable route to
                    // the shared Downloads collection on Android 10+.
                    val values = ContentValues().apply {
                        put(MediaStore.Downloads.DISPLAY_NAME, fileName)
                        put(MediaStore.Downloads.MIME_TYPE, mime)
                        put(MediaStore.Downloads.IS_PENDING, 1)
                    }
                    val resolver = context.contentResolver
                    val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values)
                        ?: return mapOf("success" to false, "error" to "could not create Downloads entry")

                    resolver.openOutputStream(uri)?.use { it.write(bytes) }
                        ?: return mapOf("success" to false, "error" to "could not open Downloads entry")

                    values.clear()
                    values.put(MediaStore.Downloads.IS_PENDING, 0)
                    resolver.update(uri, values, null, null)
                    uri.toString()
                } else {
                    val dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS)
                    if (!dir.exists()) dir.mkdirs()
                    val target = uniqueFile(dir, fileName)
                    FileOutputStream(target).use { it.write(bytes) }
                    target.absolutePath
                }

                context.runOnUiThread {
                    Toast.makeText(context, "Saved to Downloads: $fileName", Toast.LENGTH_LONG).show()
                }

                mapOf("success" to true, "path" to savedPath, "file_name" to fileName)
            } catch (e: Throwable) {
                mapOf("success" to false, "error" to (e.message ?: e.toString()))
            }
        }
    }

    /**
     * Expose a device-local file over the loopback media server and return the
     * URL to feed straight to <video>/<audio>. See LocalMediaServer for why an
     * ordinary same-origin URL cannot work here.
     */
    class Serve(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val path = parameters["path"] as? String
                ?: return mapOf("success" to false, "error" to "path is required")
            val token = parameters["token"] as? String ?: path.hashCode().toString()

            val url = LocalMediaServer.serve(token, path)
                ?: return mapOf("success" to false, "error" to "could not serve file")

            return mapOf("success" to true, "url" to url)
        }
    }

    /** Strip anything that cannot legally appear in a file name. */
    private fun sanitize(name: String): String {
        val cleaned = name.trim().replace(Regex("[\\\\/:*?\"<>|]"), "_")
        return if (cleaned.isBlank()) "download" else cleaned
    }

    private fun guessName(url: String): String {
        val last = url.substringAfterLast('/').substringBefore('?')
        return if (last.isBlank()) "download" else last
    }

    private fun guessMime(fileName: String): String {
        val ext = fileName.substringAfterLast('.', "")
        return MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext.lowercase())
            ?: "application/octet-stream"
    }

    /** Never overwrite an existing download — mirror the browser's "name (1)". */
    private fun uniqueFile(dir: File, fileName: String): File {
        var candidate = File(dir, fileName)
        if (!candidate.exists()) return candidate

        val base = fileName.substringBeforeLast('.', fileName)
        val ext = fileName.substringAfterLast('.', "")
        var i = 1
        while (candidate.exists()) {
            val next = if (ext.isBlank()) "$base ($i)" else "$base ($i).$ext"
            candidate = File(dir, next)
            i++
        }
        return candidate
    }
}
