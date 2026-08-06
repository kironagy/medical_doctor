package com.medicalplus.backgroundsync

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat

/**
 * Keeps the process alive while a sync or a large chunked upload is in
 * progress, independent of MainActivity's lifecycle.
 *
 * WHY THIS EXISTS: the embedded PHP runtime processes a sync or an offline
 * upload on a background Thread (PHPQueueWorker / the chunk upload loop).
 * A plain background Thread is NOT protected from the OS killing the whole
 * process once there is no visible Activity — which is exactly why closing
 * the app (or letting the screen lock) could cut off an in-progress sync or
 * upload. Calling startForeground() here with an ongoing notification tells
 * Android this process is doing user-visible, important work, which keeps it
 * alive until stopForeground()/stopSelf() is called — regardless of whether
 * MainActivity exists.
 *
 * This file is NOT the actual sync logic. It only owns the notification and
 * the elevated process priority; the real work continues to run wherever it
 * already ran (PHPQueueWorker's dedicated PHP runtime, or the JS-driven chunk
 * upload loop going through the app's normal PHP request path). Start/Progress/
 * Stop are called from PHP via the BackgroundSync facade at the points where
 * that work begins, advances, and ends.
 */
class SyncForegroundService : Service() {

    companion object {
        const val ACTION_START = "com.medicalplus.backgroundsync.action.START"
        const val ACTION_UPDATE = "com.medicalplus.backgroundsync.action.UPDATE"
        const val ACTION_STOP = "com.medicalplus.backgroundsync.action.STOP"

        const val EXTRA_TITLE = "title"
        const val EXTRA_MESSAGE = "message"
        const val EXTRA_PERCENT = "percent"

        private const val CHANNEL_ID = "background_sync"
        private const val NOTIFICATION_ID = 872_001
        private const val DONE_NOTIFICATION_ID = 872_002
    }

    private var currentTitle: String = "Syncing"

    override fun onCreate() {
        super.onCreate()
        createChannelIfNeeded()

        // Android requires startForeground() within 5s of a service created via
        // startForegroundService(), or the process crashes with
        // "did not then call Service.startForeground()". Satisfying that here,
        // unconditionally, means it is never possible to violate the contract
        // no matter which action (Start/Progress/even a stray Stop with no
        // prior Start) caused the OS to create this instance — onStartCommand
        // below then immediately replaces it with the real notification, or
        // stops the service right after if the action was ACTION_STOP.
        goForeground(currentTitle, "", null)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> {
                currentTitle = intent.getStringExtra(EXTRA_TITLE) ?: currentTitle
                val message = intent.getStringExtra(EXTRA_MESSAGE) ?: ""
                goForeground(currentTitle, message, null)
            }

            ACTION_UPDATE -> {
                val message = intent.getStringExtra(EXTRA_MESSAGE) ?: ""
                val percent = if (intent.hasExtra(EXTRA_PERCENT)) {
                    intent.getIntExtra(EXTRA_PERCENT, -1)
                } else {
                    null
                }
                goForeground(currentTitle, message, percent)
            }

            ACTION_STOP -> {
                val finalMessage = intent.getStringExtra(EXTRA_MESSAGE)
                showCompletionNotification(finalMessage)
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
            }
        }

        // Deliberately NOT START_STICKY: if the process is killed, the queued
        // job/upload resumes on its own the next time PHP runs (queue table /
        // chunk session both persist to SQLite) — restarting an empty service
        // with no PHP work attached would just show a stuck notification.
        return START_NOT_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createChannelIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = getSystemService(NotificationManager::class.java) ?: return
        if (manager.getNotificationChannel(CHANNEL_ID) != null) return

        val channel = NotificationChannel(
            CHANNEL_ID,
            "Sync & Uploads",
            NotificationManager.IMPORTANCE_LOW,
        )
        channel.description = "Progress of background sync and file uploads"
        manager.createNotificationChannel(channel)
    }

    private fun buildNotification(title: String, message: String, percent: Int?): Notification {
        val builder = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(title)
            .setContentText(message)
            .setSmallIcon(applicationInfo.icon)
            .setOnlyAlertOnce(true)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)

        if (percent != null && percent in 0..100) {
            builder.setProgress(100, percent, false)
        } else {
            builder.setProgress(0, 0, true)
        }

        return builder.build()
    }

    private fun goForeground(title: String, message: String, percent: Int?) {
        val notification = buildNotification(title, message, percent)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun showCompletionNotification(message: String?) {
        val manager = getSystemService(NotificationManager::class.java) ?: return

        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(currentTitle)
            .setContentText(message ?: "Done")
            .setSmallIcon(applicationInfo.icon)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()

        manager.notify(DONE_NOTIFICATION_ID, notification)
    }
}
