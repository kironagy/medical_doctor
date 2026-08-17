package com.medicalplus.appcontrol

import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Bridge entry points for AppControl.* — see nativephp.json for the
 * PHP-visible names.
 *
 * Why this exists: the embedded single-user device auto-logs the doctor
 * back in (AuthController::showLogin()) the instant it sees a local user
 * row, which happens again the moment the WebView reloads /login after a
 * plain client-side redirect — clearing the session server-side is not
 * enough on its own to guarantee the doctor actually lands on a blank login
 * form. Killing the whole process on logout forces the next launch to be a
 * genuine cold boot instead.
 */
object AppControlFunctions {

    class Exit(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Posted with a short delay so this function's own return value
            // has a chance to make it back across the bridge before the
            // process disappears from under it.
            Handler(Looper.getMainLooper()).postDelayed({
                context.finishAndRemoveTask()
                android.os.Process.killProcess(android.os.Process.myPid())
            }, 200)

            return mapOf("success" to true)
        }
    }
}
