package com.medicalplus.backgroundsync

import android.content.Intent
import android.os.Build
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Bridge entry points for BackgroundSync.* — see nativephp.json for the
 * PHP-visible names. Each function just forwards to SyncForegroundService via
 * an Intent; the actual notification/lifecycle logic lives there.
 */
object BackgroundSyncFunctions {

    class Start(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val title = parameters["title"] as? String ?: "Syncing"
            val message = parameters["message"] as? String ?: ""

            val intent = Intent(context.applicationContext, SyncForegroundService::class.java).apply {
                action = SyncForegroundService.ACTION_START
                putExtra(SyncForegroundService.EXTRA_TITLE, title)
                putExtra(SyncForegroundService.EXTRA_MESSAGE, message)
            }
            startService(context, intent)

            return mapOf("success" to true)
        }
    }

    class Progress(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val message = parameters["message"] as? String ?: ""
            val percent = (parameters["percent"] as? Number)?.toInt()

            val intent = Intent(context.applicationContext, SyncForegroundService::class.java).apply {
                action = SyncForegroundService.ACTION_UPDATE
                putExtra(SyncForegroundService.EXTRA_MESSAGE, message)
                if (percent != null) putExtra(SyncForegroundService.EXTRA_PERCENT, percent)
            }
            startService(context, intent)

            return mapOf("success" to true)
        }
    }

    class Stop(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val finalMessage = parameters["message"] as? String

            val intent = Intent(context.applicationContext, SyncForegroundService::class.java).apply {
                action = SyncForegroundService.ACTION_STOP
                if (finalMessage != null) putExtra(SyncForegroundService.EXTRA_MESSAGE, finalMessage)
            }
            startService(context, intent)

            return mapOf("success" to true)
        }
    }

    private fun startService(context: FragmentActivity, intent: Intent) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            context.applicationContext.startForegroundService(intent)
        } else {
            context.applicationContext.startService(intent)
        }
    }
}
