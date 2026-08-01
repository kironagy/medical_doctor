
❯ adb logcat -c
adb logcat | grep -E "PHPMonitor|RequestRouter|NetworkStateManager|Hybrid|Sync|SQLite|Patient|Repository|Workspace|Api|Local|Database|Room|SQL|Insert|Create|Offline|ERROR|Exception"
08-01 17:27:19.697 28409 28409 D PHPMonitor: onShowFileChooser()
08-01 17:27:19.698 28409 28409 D PHPMonitor: Launching Picker
08-01 17:27:19.793  3143 11686 D ActivityTaskManager: getStartingWindowType: , newTask=false, taskSwitch=false, processRunning=true, allowTaskSnapshot=true, activityCreated=false, activityAllDrawn=false, snapshot exist: false
08-01 17:27:19.902 30255 30255 E OplusPredictiveBackController:  NoSuchMethodException
08-01 17:27:19.902 30255 30255 E OplusPredictiveBackController:  NoSuchMethodException
08-01 17:27:19.904 30255 30255 I ChooserActivity: onCreate
08-01 17:27:19.943 15958 15958 I PluginSeedling--Notification: NotificationRepository-->onDataChanged, []
08-01 17:27:20.087  3143  7916 D ActivityTaskManager: getStartingWindowType: , newTask=false, taskSwitch=false, processRunning=true, allowTaskSnapshot=true, activityCreated=false, activityAllDrawn=false, snapshot exist: false
08-01 17:27:20.096 30255 30255 D ActivityClient: activity finished by caller: android.app.Activity.finish:7644 android.app.Activity.finish:7661 com.android.intentresolver.ChooserActivity.maybeAutolaunchActivity:117 com.android.intentresolver.ChooserActivity.postRebuildList:15 com.android.intentresolver.ChooserActivity$$ExternalSyntheticLambda4.run:106 com.android.intentresolver.ChooserHelper.onCreate:238 androidx.lifecycle.DefaultLifecycleObserverAdapter.onStateChanged:43 androidx.lifecycle.LifecycleRegistry$ObserverWithState.dispatchEvent:21 androidx.lifecycle.LifecycleRegistry.sync:352 androidx.lifecycle.LifecycleRegistry.moveToState:130
08-01 17:27:20.141  2151  2151 W android.hardware.power.stats-impl.oplus: PruneException: Unknown binder exception (2) pruned into EX_TRANSACTION_FAILED
08-01 17:27:20.145 30317 30349 I AdrenoVK-0: Local Branch            :
08-01 17:27:20.145 30317 30349 I AdrenoVK-0: Api Version         : 0x00401000
08-01 17:27:20.168  2151  2151 W android.hardware.power.stats-impl.oplus: PruneException: Unknown binder exception (2) pruned into EX_TRANSACTION_FAILED
08-01 17:27:20.176 15958 15958 I PluginSeedling--Notification: NotificationRepository-->onDataChanged, []
08-01 17:27:20.250 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: java.io.FileNotFoundException: name
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: 	at com.android.documentsui.base.DocumentInfo.asFileNotFoundException(DocumentInfo.java:588)
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: 	at com.android.documentsui.picker.PickActivity.onCreate(PickActivity.java:300)
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: 	at android.app.Activity.performCreate(Activity.java:9400)
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: 	at android.app.Activity.performCreate(Activity.java:9372)
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: 	at android.app.Instrumentation.callActivityOnCreate(Instrumentation.java:1541)
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: Caused by: java.lang.NullPointerException: name
08-01 17:27:20.260 30317 30317 W LastAccessedStorage: 	at java.util.Objects.throwNullPointerException(Objects.java:508)
08-01 17:27:20.271 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.273 30317 30367 W RefreshTask: java.lang.NullPointerException: name
08-01 17:27:20.273 30317 30367 W RefreshTask: 	at java.util.Objects.throwNullPointerException(Objects.java:508)
08-01 17:27:20.364 30317 30373 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.365 18163 17478 W SQLiteQueryBuilder: Allowing abusive custom column: null as oem_metadata
08-01 17:27:20.367 18163 17478 W SQLiteQueryBuilder: Allowing abusive custom column: null as oem_metadata
08-01 17:27:20.370 30317 30373 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.388 30317 30373 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.397 30317 30317 D VRI[PickActivity]: relayoutWindow result, sizeChanged:true, surfaceControlChanged:true, transformHintChanged:false, mSurfaceSize:Point(1080, 2372), mLastSurfaceSize:Point(0, 0), mWidth:-1, mHeight:-1, requestedWidth:1080, requestedHeight:2372, transformHint:0, installOrientation:0, displayRotation:0, isSurfaceValid:true, attr.flag:-2122252032, tmpFrames:ClientWindowFrames{frame=[0,0][1080,2372] display=[0,0][1080,2372] parentFrame=[0,0][0,0]}, relayoutAsync:false, mSyncSeqId:0
08-01 17:27:20.427 30317 30370 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.436 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.437 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.438 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.439 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.440 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.440 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.440 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.440 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.440 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.440 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.440 30317 30419 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.459 30317 30317 D VRI[PickActivity]: relayoutWindow result, sizeChanged:false, surfaceControlChanged:false, transformHintChanged:false, mSurfaceSize:Point(1080, 2372), mLastSurfaceSize:Point(1080, 2372), mWidth:1080, mHeight:2372, requestedWidth:1080, requestedHeight:2372, transformHint:0, installOrientation:0, displayRotation:0, isSurfaceValid:true, attr.flag:-2122252032, tmpFrames:ClientWindowFrames{frame=[0,0][1080,2372] display=[0,0][1080,2372] parentFrame=[0,0][0,0]}, relayoutAsync:false, mSyncSeqId:0
08-01 17:27:20.624  3143  7225 D WindowManager: onSyncFinishedDrawing: Window{cbc9c65 u0 com.google.android.documentsui/com.android.documentsui.picker.PickActivity}
08-01 17:27:20.626  3143  7225 I WindowManager: finishDrawing skipLayout:false,syncSeqId=0,mPrepareSyncSeqId=0 Window{cbc9c65 u0 com.google.android.documentsui/com.android.documentsui.picker.PickActivity} com.android.server.wm.WindowManagerService.finishDrawingWindow:3223 com.android.server.wm.Session.finishDrawing:399 android.view.IWindowSession$Stub.onTransact:793
08-01 17:27:20.643 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.643 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.643 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.662 15958 16010 D OplusShellTransition: dispatchReady, active:(#4115) android.os.BinderProxy@210717a@0, isSync=false, track:com.android.wm.shell.transition.Transitions$Track@c558bd4
08-01 17:27:20.675 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.675 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.678 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.678 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.678 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.686 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.686 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.687 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.687 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.687 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.699 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.699 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.700 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.700 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.700 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.705 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.705 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.706 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.706 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.706 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.716 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.716 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.717 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.717 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.717 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.722 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.722 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.723 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.724 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.724 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.733 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.733 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.734 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.734 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.734 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.737  3143  7916 I BLASTSyncEngine: onCommitted syncId: 4115, timeout: false ran: false
08-01 17:27:20.740 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.740 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.741 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.741 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.741 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.751 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.751 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.752 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.752 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.752 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.757 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.757 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.758 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:20.966 15958 15958 I SystemUi--Notification: OplusLiveAlertNotificationsRepository-->removeLiveAlert: 0|android|0|com.android.server.wm.AlertWindowNotification - com.wispr.flowapp|1000
08-01 17:27:20.985 15958 15958 I SystemUi--Notification: OplusLiveAlertNotificationsRepository-->onRankingApplied
08-01 17:27:20.992 15958 15958 I SystemUi--Notification: OplusLiveAlertNotificationsRepository-->onNotificationChannelModified: android 0 Wispr Flow displaying over other apps 3
08-01 17:27:21.181 18734 18734 I GoogleInputMethodService: GoogleInputMethodService.onStartInput():1491 onStartInput(EditorInfo{EditorInfo{packageName=com.google.android.documentsui, inputType=0, inputTypeString=NULL, enableLearning=false, autoCorrection=false, autoComplete=false, imeOptions=0, privateImeOptions=null, actionName=UNSPECIFIED, actionLabel=null, initialSelStart=-1, initialSelEnd=-1, initialCapsMode=0, label=null, fieldId=0, fieldName=null, extras=Bundle[{com.oplus.im.WINDOW_MODE=1, com.oplus.im.SCENES=0}], hintText=null, hintLocales=[]}}, false)
08-01 17:27:21.447 28409 28409 D VRI[MainActivity]: relayoutWindow result, sizeChanged:false, surfaceControlChanged:true, transformHintChanged:false, mSurfaceSize:Point(1080, 2372), mLastSurfaceSize:Point(1080, 2372), mWidth:1080, mHeight:2372, requestedWidth:1080, requestedHeight:2372, transformHint:0, installOrientation:0, displayRotation:0, isSurfaceValid:false, attr.flag:-2122252032, tmpFrames:ClientWindowFrames{frame=[0,0][1080,2372] display=[0,0][1080,2372] parentFrame=[0,0][0,0]}, relayoutAsync:false, mSyncSeqId:0
08-01 17:27:21.690 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:21.690 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:21.691 30317 31725 W FlagUtils: enableSyncState() returns false
08-01 17:27:21.728 15958 15958 I PluginSeedling--Notification: NotificationRepository-->onDataChanged, []
08-01 17:27:21.763  2151  2151 W android.hardware.power.stats-impl.oplus: PruneException: Unknown binder exception (2) pruned into EX_TRANSACTION_FAILED
08-01 17:27:21.768 28409 28409 D PHPMonitor: Picker Returned
08-01 17:27:21.768 28409 28409 D PHPMonitor: ResultCode: -1
08-01 17:27:21.768 28409 28409 D PHPMonitor: Returned URI: content://com.android.providers.media.documents/document/image%3A95772
08-01 17:27:21.784 28409 28409 D PHPMonitor: Returned MIME: image/jpeg
08-01 17:27:21.784 28409 28409 D PHPMonitor: Returned Count: 1
08-01 17:27:21.802 28409 28409 D VRI[MainActivity]: relayoutWindow result, sizeChanged:false, surfaceControlChanged:true, transformHintChanged:false, mSurfaceSize:Point(1080, 2372), mLastSurfaceSize:Point(1080, 2372), mWidth:1080, mHeight:2372, requestedWidth:1080, requestedHeight:2372, transformHint:0, installOrientation:0, displayRotation:0, isSurfaceValid:true, attr.flag:-2122252032, tmpFrames:ClientWindowFrames{frame=[0,0][1080,2372] display=[0,0][1080,2372] parentFrame=[0,0][0,0]}, relayoutAsync:false, mSyncSeqId:0
08-01 17:27:21.824  3143  7916 D WindowManager: onSyncFinishedDrawing: Window{de81ac1 u0 com.medicalplus.app/com.nativephp.mobile.ui.MainActivity}
08-01 17:27:21.825  3143  7916 I WindowManager: finishDrawing skipLayout:false,syncSeqId=0,mPrepareSyncSeqId=0 Window{de81ac1 u0 com.medicalplus.app/com.nativephp.mobile.ui.MainActivity} com.android.server.wm.WindowManagerService.finishDrawingWindow:3223 com.android.server.wm.Session.finishDrawing:399 android.view.IWindowSession$Stub.onTransact:793
08-01 17:27:21.829 28409 28409 D PHPMonitor-Console: [SyncEngine] 🟢 PATH 3: App became visible (navigator.onLine=true) -- From line 1
08-01 17:27:21.830 28409 28409 D PHPMonitor-Console: [SyncEngine] 🌐 Network restored via visibility:change — starting sync -- From line 1
08-01 17:27:21.844 15958 16010 D OplusShellTransition: dispatchReady, active:(#4116) android.os.BinderProxy@95f9ff@0, isSync=false, track:com.android.wm.shell.transition.Transitions$Track@c558bd4
08-01 17:27:21.881  3143  8452 I BLASTSyncEngine: onCommitted syncId: 4116, timeout: false ran: false
08-01 17:27:22.056 18734 18734 I GoogleInputMethodService: GoogleInputMethodService.onStartInput():1491 onStartInput(EditorInfo{EditorInfo{packageName=com.medicalplus.app, inputType=0, inputTypeString=NULL, enableLearning=false, autoCorrection=false, autoComplete=false, imeOptions=12000000, privateImeOptions=null, actionName=UNSPECIFIED, actionLabel=null, initialSelStart=-1, initialSelEnd=-1, initialCapsMode=0, label=null, fieldId=0, fieldName=null, extras=Bundle[{com.oplus.im.WINDOW_MODE=1, com.oplus.im.SCENES=0}], hintText=null, hintLocales=[]}}, false)
08-01 17:27:22.230 15958 15958 I SystemUi--Notification: OplusLiveAlertNotificationsRepository-->onNotificationChannelModified: android 0 Wispr Flow displaying over other apps 1
08-01 17:27:22.271 30317 30317 D VRI[PickActivity]: relayoutWindow result, sizeChanged:false, surfaceControlChanged:true, transformHintChanged:false, mSurfaceSize:Point(1080, 2372), mLastSurfaceSize:Point(1080, 2372), mWidth:1080, mHeight:2372, requestedWidth:1080, requestedHeight:2372, transformHint:0, installOrientation:0, displayRotation:0, isSurfaceValid:false, attr.flag:-2122252032, tmpFrames:ClientWindowFrames{frame=[0,0][1080,2372] display=[0,0][1080,2372] parentFrame=[0,0][0,0]}, relayoutAsync:false, mSyncSeqId:0
08-01 17:27:22.292 15958 15958 I SystemUi--Notification: OplusLiveAlertNotificationsRepository-->onRankingApplied
08-01 17:27:22.331 28409 28409 D PHPMonitor-Console: [SyncEngine] 🚀 POST /_native/api/sync/engine — Starting full sync cycle... -- From line 1
08-01 17:27:22.350 28409 28987 D PHPMonitor-JS: 📦 POST data captured (fetch/XHR) for: /_native/api/sync/engine reqId=nphp_7_1785594442344 (length=2, boundary=)
08-01 17:27:22.352 30317 30317 W FlagUtils: enableSyncState() returns false
08-01 17:27:22.353 30317 30317 D OplusScrollToTopManager: com.google.android.documentsui/com.android.documentsui.picker.PickActivity, unregisterSystemUIBroadcastReceiver failed java.lang.IllegalArgumentException: Receiver not registered: android.view.OplusScrollToTopManager$2@1192e0
08-01 17:27:22.358 28409 28609 D PHPMonitor: 🔄 Intercepting POST request to https://prof-hosam-fekry.online/_native/api/sync/engine
08-01 17:27:22.358 28409 28609 D PHPMonitor-Headers: 📋 Authorization: Bearer 491|NwP2CWFchq6P7hRpeeda4SEtNgY9sStKTxrSHkPo938a3a4c
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 Origin: https://prof-hosam-fekry.online
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 X-NativePHP-Req-Id: nphp_7_1785594442344
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 sec-ch-ua: "Not;A=Brand";v="8", "Chromium";v="150", "Android WebView";v="150"
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 sec-ch-ua-mobile: ?1
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 X-Requested-With: XMLHttpRequest
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 Accept: application/json, text/plain, */*
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 X-XSRF-TOKEN: eyJpdiI6Imh3WFM0eHVvQ2h6TEc0eU1Tc2kvUWc9PSIsInZhbHVlIjoiS091VkZ1T25GOU1MeWVoaE45OGJlOWFQQWdOZE1aM01YSHhCYlluUzFhQU1YWVE0TmZpaktPK2FLUVRDR2IzTjFyYnltTTkrSWkrQnlKYWJVeVR6Y1c2QTRhOW5ZeDJ1VFBTcnFVRlJrRW83WENFREREOERVc085aG5kQm8vOUgiLCJtYWMiOiIwNjY5MWQ5MWE3NWE1Mjk0YWIyYzYwMWVlZWU5MDBmMGUzYThjYTVmZTY5OWFjNzI0MWQ1MzVlZDMzYjQ5N2YxIiwidGFnIjoiIn0=
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 sec-ch-ua-platform: "Android"
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 User-Agent: Mozilla/5.0 (Linux; Android 16; CPH2743 Build/BP2A.250605.015; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/150.0.7871.181 Mobile Safari/537.36
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 Referer: https://prof-hosam-fekry.online/dashboard
08-01 17:27:22.359 28409 28609 D PHPMonitor-Headers: 📋 Content-Type: application/json
08-01 17:27:22.363 28409 28609 D RequestRouter: 📦 POST /_native/api/sync/engine (host: prof-hosam-fekry.online) -> LOCAL_PHP | ONLINE | Local native endpoint — always embedded Laravel
08-01 17:27:22.363 28409 28609 D PHPMonitor: 🌐 Phase 7 — PHP request: https://prof-hosam-fekry.online/_native/api/sync/engine
08-01 17:27:22.406 15958 15958 I SystemUi--Notification: OplusLiveAlertNotificationsRepository-->onNotificationChannelModified: android 0 Wispr Flow displaying over other apps 2
08-01 17:27:22.417 15958 16172 D LocalImageResolver: Couldn't use ImageDecoder for drawable, falling back to non-resized load.
08-01 17:27:22.419 15958 15958 D OplusNotificationDateTimeView: setTime mSettingTimeMillis 1785594442215, mSettingLocalDateTime 2026-08-01T17:27:00.215
08-01 17:27:22.431 15958 16172 D LocalImageResolver: Couldn't use ImageDecoder for drawable, falling back to non-resized load.
08-01 17:27:22.436 15958 15958 D OplusNotificationDateTimeView: setTime mSettingTimeMillis 1785594442215, mSettingLocalDateTime 2026-08-01T17:27:00.215
08-01 17:27:22.445 15958 16172 D LocalImageResolver: Couldn't use ImageDecoder for drawable, falling back to non-resized load.
08-01 17:27:22.446 15958 15958 D OplusNotificationDateTimeView: setTime mSettingTimeMillis 1785594442234, mSettingLocalDateTime 2026-08-01T17:27:00.234
08-01 17:27:22.850 28409 28409 D PHPMonitor-Console: [SyncEngine] 📡 GET /_native/api/sync/pending-summary -- From line 1
08-01 17:27:22.850 28409 28611 D PHPMonitor: 🔄 Intercepting GET request to https://prof-hosam-fekry.online/_native/api/sync/pending-summary
08-01 17:27:22.850 28409 28611 D PHPMonitor-Headers: 📋 sec-ch-ua: "Not;A=Brand";v="8", "Chromium";v="150", "Android WebView";v="150"
08-01 17:27:22.850 28409 28611 D PHPMonitor-Headers: 📋 sec-ch-ua-mobile: ?1
08-01 17:27:22.850 28409 28611 D PHPMonitor-Headers: 📋 X-Requested-With: XMLHttpRequest
08-01 17:27:22.850 28409 28611 D PHPMonitor-Headers: 📋 Accept: application/json, text/plain, */*
08-01 17:27:22.850 28409 28611 D PHPMonitor-Headers: 📋 X-XSRF-TOKEN: eyJpdiI6Imh3WFM0eHVvQ2h6TEc0eU1Tc2kvUWc9PSIsInZhbHVlIjoiS091VkZ1T25GOU1MeWVoaE45OGJlOWFQQWdOZE1aM01YSHhCYlluUzFhQU1YWVE0TmZpaktPK2FLUVRDR2IzTjFyYnltTTkrSWkrQnlKYWJVeVR6Y1c2QTRhOW5ZeDJ1VFBTcnFVRlJrRW83WENFREREOERVc085aG5kQm8vOUgiLCJtYWMiOiIwNjY5MWQ5MWE3NWE1Mjk0YWIyYzYwMWVlZWU5MDBmMGUzYThjYTVmZTY5OWFjNzI0MWQ1MzVlZDMzYjQ5N2YxIiwidGFnIjoiIn0=
08-01 17:27:22.851 28409 28611 D PHPMonitor-Headers: 📋 sec-ch-ua-platform: "Android"
08-01 17:27:22.851 28409 28611 D PHPMonitor-Headers: 📋 User-Agent: Mozilla/5.0 (Linux; Android 16; CPH2743 Build/BP2A.250605.015; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/150.0.7871.181 Mobile Safari/537.36
08-01 17:27:22.852 28409 28611 D PHPMonitor-Headers: 📋 Referer: https://prof-hosam-fekry.online/dashboard
08-01 17:27:22.861 28409 28611 D RequestRouter: 📦 GET /_native/api/sync/pending-summary (host: prof-hosam-fekry.online) -> LOCAL_PHP | ONLINE | Local native endpoint — always embedded Laravel
08-01 17:27:22.861 28409 28611 D PHPMonitor: 🌐 Phase 7 — PHP request: https://prof-hosam-fekry.online/_native/api/sync/pending-summary
08-01 17:27:23.023 28409 28987 D PHPMonitor-JS: 📦 POST data captured (fetch/XHR) for: /api/v1/mobile/patients/79edd315-9c58-4a90-8bfe-f8cbcb7a4d07/files reqId=nphp_8_1785594442651 (length=1716851, boundary=----WebKitFormBoundaryyjhcigzki3msagttp7)
08-01 17:27:23.067 28409 28610 D PHPMonitor: 🔄 Intercepting POST request to https://prof-hosam-fekry.online/api/v1/mobile/patients/79edd315-9c58-4a90-8bfe-f8cbcb7a4d07/files
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 Origin: https://prof-hosam-fekry.online
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 X-NativePHP-Req-Id: nphp_8_1785594442651
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 sec-ch-ua: "Not;A=Brand";v="8", "Chromium";v="150", "Android WebView";v="150"
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 sec-ch-ua-mobile: ?1
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 X-Requested-With: XMLHttpRequest
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 Accept: application/json, text/plain, */*
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 X-XSRF-TOKEN: eyJpdiI6Imh3WFM0eHVvQ2h6TEc0eU1Tc2kvUWc9PSIsInZhbHVlIjoiS091VkZ1T25GOU1MeWVoaE45OGJlOWFQQWdOZE1aM01YSHhCYlluUzFhQU1YWVE0TmZpaktPK2FLUVRDR2IzTjFyYnltTTkrSWkrQnlKYWJVeVR6Y1c2QTRhOW5ZeDJ1VFBTcnFVRlJrRW83WENFREREOERVc085aG5kQm8vOUgiLCJtYWMiOiIwNjY5MWQ5MWE3NWE1Mjk0YWIyYzYwMWVlZWU5MDBmMGUzYThjYTVmZTY5OWFjNzI0MWQ1MzVlZDMzYjQ5N2YxIiwidGFnIjoiIn0=
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 sec-ch-ua-platform: "Android"
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 User-Agent: Mozilla/5.0 (Linux; Android 16; CPH2743 Build/BP2A.250605.015; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/150.0.7871.181 Mobile Safari/537.36
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 Referer: https://prof-hosam-fekry.online/dashboard
08-01 17:27:23.067 28409 28610 D PHPMonitor-Headers: 📋 Content-Type: multipart/form-data; boundary=----WebKitFormBoundaryGEzVOBbCoY5Is2wM
08-01 17:27:23.075 28409 28610 D RequestRouter: 📦 POST /api/v1/mobile/patients/79edd315-9c58-4a90-8bfe-f8cbcb7a4d07/files (host: prof-hosam-fekry.online) -> LOCAL_PHP | ONLINE | Online + API mutation — embedded Laravel (local save + remote sync)
08-01 17:27:23.075 28409 28610 D PHPMonitor: 🌐 Phase 7 — PHP request: https://prof-hosam-fekry.online/api/v1/mobile/patients/79edd315-9c58-4a90-8bfe-f8cbcb7a4d07/files
08-01 17:27:23.496 31743 31743 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:23.521 31743 31753 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:23.531 28409 29728 E PHPBridge: {"success":true,"message":"Sync cycle completed","results":{"patients":0,"files":{"uploaded":0,"failed":0},"notes":{"uploaded":0,"deleted":0,"failed":0},"deletes":0,"file_deletes":0,"file_updates":0,"local_files":{"uploaded":0,"failed":0}}}el\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php","line":768,"trace":"#0 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php(716): Illuminate\\Foundation\\Exceptions\\Handler->prepareException(Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#1 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nunomaduro\/collision\/src\/Adapters\/Laravel\/ExceptionHandler.php(55): Illuminate\\Foundation\\Exceptions\\Handler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#2 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Pipeline.php(51): NunoMaduro\\Collision\\Adapters\\Laravel\\ExceptionHandler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#3 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(182): Illuminate\\Routing\\Pipeline->handleException(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#4 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Middleware\/SubstituteBindings.php(52): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#5 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(219): Illuminate\\Routing\\Middleware\\SubstituteBindings->handle(Object(Illuminate\\Http\\Request), Object(Closure))\n#6 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(137): Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}(Object(Illuminate\\Http\\Request))\n#7 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(821): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#8 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(800): Illuminate\\Routing\\Router->runRouteWithinStack(Object(Illuminate\\Routing\\Route), Object(Illuminate\\Http\\Request))\n#9 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(764): Illuminate\\Routing\\Router->runRoute(Object(Illuminate\\Http\\Request), Object(Illuminate\\Routing\\Route))\n#10 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(753): Illuminate\\Routing\\Router->dispatchToRoute(Object(Illuminate\\Http\\Request))\n#11 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Http\/Kernel.php(200): Illuminate\\Routing\\Router->dispatch(Object(Illuminate\\Http\\Request))\n#12 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(180): Illuminate\\Foundation\\Http\\Kernel->{closure:Illuminate\\Foundation\\Http\\Kernel::dispatchToRouter():197}(Object(Illuminate\\Http\\Request))\n#13 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nativephp\/mobile\/src\/Http\/Middleware\/RenderEdgeComponents.php(14): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#14 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/
08-01 17:27:23.554 28409 28409 D PHPMonitor-Console: [SyncEngine] ✅ POST /_native/api/sync/engine — status=200 success=true -- From line 1
08-01 17:27:23.554 28409 28409 D PHPMonitor-Console: [SyncEngine] 📊 Sync result: {"success":true,"patients":0,"files_uploaded":0,"files_failed":0,"deletes":0,"timestamp":"2026-08-01T14:27:23.553Z","message":"Sync cycle completed"} -- From line 1
08-01 17:27:23.555 28409 28409 D PHPMonitor-Console: [SyncEngine] 📡 GET /_native/api/sync/pending-summary -- From line 1
08-01 17:27:23.563 28409 28609 D PHPMonitor: 🔄 Intercepting GET request to https://prof-hosam-fekry.online/_native/api/sync/pending-summary
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 sec-ch-ua: "Not;A=Brand";v="8", "Chromium";v="150", "Android WebView";v="150"
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 sec-ch-ua-mobile: ?1
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 X-Requested-With: XMLHttpRequest
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 Accept: application/json, text/plain, */*
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 X-XSRF-TOKEN: eyJpdiI6Imh3WFM0eHVvQ2h6TEc0eU1Tc2kvUWc9PSIsInZhbHVlIjoiS091VkZ1T25GOU1MeWVoaE45OGJlOWFQQWdOZE1aM01YSHhCYlluUzFhQU1YWVE0TmZpaktPK2FLUVRDR2IzTjFyYnltTTkrSWkrQnlKYWJVeVR6Y1c2QTRhOW5ZeDJ1VFBTcnFVRlJrRW83WENFREREOERVc085aG5kQm8vOUgiLCJtYWMiOiIwNjY5MWQ5MWE3NWE1Mjk0YWIyYzYwMWVlZWU5MDBmMGUzYThjYTVmZTY5OWFjNzI0MWQ1MzVlZDMzYjQ5N2YxIiwidGFnIjoiIn0=
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 sec-ch-ua-platform: "Android"
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 User-Agent: Mozilla/5.0 (Linux; Android 16; CPH2743 Build/BP2A.250605.015; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/150.0.7871.181 Mobile Safari/537.36
08-01 17:27:23.563 28409 28609 D PHPMonitor-Headers: 📋 Referer: https://prof-hosam-fekry.online/dashboard
08-01 17:27:23.565 28409 28609 D RequestRouter: 📦 GET /_native/api/sync/pending-summary (host: prof-hosam-fekry.online) -> LOCAL_PHP | ONLINE | Local native endpoint — always embedded Laravel
08-01 17:27:23.566 28409 28609 D PHPMonitor: 🌐 Phase 7 — PHP request: https://prof-hosam-fekry.online/_native/api/sync/pending-summary
08-01 17:27:23.737  3143  7374 W Athena  : AthenaLocalService: requestId:10629, type:smart-force, [available{1540, 2200, 19, 900, 99, 1540, true}]
08-01 17:27:24.099  3143  3526 D BatteryExternalStatsWorker: begin updateExternalStatsSync reason=remove-uid flag:1
08-01 17:27:24.118  9327  9327 E chromium: [ERROR:android_webview/browser/aw_browser_terminator.cc:175] Renderer process (30702) crash detected (code -1).
08-01 17:27:24.193  3143  3526 D BatteryExternalStatsWorker: end updateExternalStatsSync
08-01 17:27:24.418  3143  7547 W ActivityManager: android.os.DeadObjectException
08-01 17:27:24.419  3143  7547 W ActivityManager: android.os.DeadObjectException
08-01 17:27:24.575  3143  7225 W BinderNative: java.lang.SecurityException: Specified package "com.google.android.googlequicksearchbox" under uid 1000 but it is not
08-01 17:27:24.710 26165 24529 I AIUnit-UnitStateManager: noteOapState: state=false oapInfo=OapInfo(oapName=ai_toolbox, oapApi=ai_toolbox) curRunningOapSet.size=0
08-01 17:27:24.710 26165 24529 I AIUnit-SuspendController: onOapSuspendFinished: false oap=OapInfo(oapName=ai_toolbox, oapApi=ai_toolbox) suspendingOapSet=[]
08-01 17:27:24.747  9327  9327 D BoundBrokerSvc: onUnbind: Intent { act=com.google.android.gms.appset.service.START dat=chimera-action:/... xflg=0x4 cmp=com.google.android.gms/.chimera.GmsApiService mCallingUid=10132 }
08-01 17:27:24.752 12040 12040 D BoundBrokerSvc: onUnbind: Intent { act=com.google.android.gms.common.telemetry.service.START dat=chimera-action:/... xflg=0x4 cmp=com.google.android.gms/.chimera.PersistentApiService mCallingUid=10130 }
08-01 17:27:24.921 31839 31839 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:24.965 31839 31856 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:25.000 30149 30187 D AIUnit-Service-LOG: AIBoostEnv: successfully loaded AIBoostCreate
08-01 17:27:25.010 30149 30187 D AIUnit-Service-LOG: AIBoostEnvLLM: successfully loaded AiboostLlmCreateSession
08-01 17:27:25.010 30149 30187 D AIUnit-Service-LOG: Dispatcher, UpdateMap start [16][OaaCreateParamK]
08-01 17:27:25.010 30149 30187 D AIUnit-Service-LOG: Dispatcher, UpdateMap [OaaCreateParamK]--[0xb400007549364fc0]-[0x33ee]
08-01 17:27:25.138 31881 31881 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:25.186 31881 31901 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:25.271 31903 31903 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:25.301 31903 31921 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:25.386 28409 28409 D PHPMonitor-Console: [SyncEngine] 📡 Pending summary: {"patients":0,"deletes":0,"files":0,"notes":0,"total":0} -- From line 1
08-01 17:27:25.452 30038 30048 W SQLiteLog: (28) double-quoted string literal: "per_settings_app_blacklist"
08-01 17:27:25.460 31881 31881 I CAR.GH  : onCreate
08-01 17:27:25.974 32045 32045 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:26.018 32045 32065 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:26.097 32045 32045 E OplusCustomizePackageManager: isOplusCertificatePackage Errorjava.lang.NullPointerException: Attempt to invoke interface method 'boolean android.os.customize.IOplusCustomizePackageManagerService.isOplusCertificatePackage(java.lang.String)' on a null object reference
08-01 17:27:26.156 31903 32075 I Finsky:background: [3058] SettingNotFoundException, fall through to G.downloadBytesOverMobileMaximum
08-01 17:27:26.172 31903 32075 I Finsky:background: [3058] SettingNotFoundException, fall through to G.downloadBytesOverMobileRecommended
08-01 17:27:26.205 31839 31939 D IndexDatabaseHelper: getNeedRefreshPackageNameList needDeletePackageList: []
08-01 17:27:26.205 31839 31939 D IndexDatabaseHelper: getNeedRefreshPackageNameList needUpdatePackageList: []
08-01 17:27:26.206 31839 31939 D DatabaseIndexingManager: deletePackageInDatabase deletePackageList is empty so return
08-01 17:27:26.395 31903 31922 D SurfaceSyncGroup: preCreateSurfaceSyncGroupTimerThread
08-01 17:27:26.398 31743 32049 D SurfaceSyncGroup: preCreateSurfaceSyncGroupTimerThread
08-01 17:27:26.398 32045 32064 D SurfaceSyncGroup: preCreateSurfaceSyncGroupTimerThread
08-01 17:27:26.405 31881 31896 D SurfaceSyncGroup: preCreateSurfaceSyncGroupTimerThread
08-01 17:27:26.958 12040 31329 W DatabaseProcessor: processLocalDevices: failed to get the network info with non-null networkId.
08-01 17:27:27.464 31743 32171 I AdrenoVK-0: Local Branch            :
08-01 17:27:27.464 31743 32171 I AdrenoVK-0: Api Version         : 0x00401000
08-01 17:27:27.915 28409 29728 E PHPBridge: {"error":true,"exception":"Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException","message":"No query results for model [App\\Domains\\Patients\\Models\\Patient].","sqlstate":null,"sqlite_error":null,"file":"\/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php","line":768,"trace":"#0 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php(716): Illuminate\\Foundation\\Exceptions\\Handler->prepareException(Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#1 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nunomaduro\/collision\/src\/Adapters\/Laravel\/ExceptionHandler.php(55): Illuminate\\Foundation\\Exceptions\\Handler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#2 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Pipeline.php(51): NunoMaduro\\Collision\\Adapters\\Laravel\\ExceptionHandler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#3 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(182): Illuminate\\Routing\\Pipeline->handleException(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#4 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Middleware\/SubstituteBindings.php(52): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#5 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(219): Illuminate\\Routing\\Middleware\\SubstituteBindings->handle(Object(Illuminate\\Http\\Request), Object(Closure))\n#6 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(137): Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}(Object(Illuminate\\Http\\Request))\n#7 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(821): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#8 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(800): Illuminate\\Routing\\Router->runRouteWithinStack(Object(Illuminate\\Routing\\Route), Object(Illuminate\\Http\\Request))\n#9 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(764): Illuminate\\Routing\\Router->runRoute(Object(Illuminate\\Http\\Request), Object(Illuminate\\Routing\\Route))\n#10 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(753): Illuminate\\Routing\\Router->dispatchToRoute(Object(Illuminate\\Http\\Request))\n#11 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Http\/Kernel.php(200): Illuminate\\Routing\\Router->dispatch(Object(Illuminate\\Http\\Request))\n#12 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(180): Illuminate\\Foundation\\Http\\Kernel->{closure:Illuminate\\Foundation\\Http\\Kernel::dispatchToRouter():197}(Object(Illuminate\\Http\\Request))\n#13 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nativephp\/mobile\/src\/Http\/Middleware\/RenderEdgeComponents.php(14): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#14 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/v
08-01 17:27:27.965 28409 28610 E PHPRequestHandler: {"error":true,"exception":"Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException","message":"No query results for model [App\\Domains\\Patients\\Models\\Patient].","sqlstate":null,"sqlite_error":null,"file":"\/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php","line":768,"trace":"#0 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php(716): Illuminate\\Foundation\\Exceptions\\Handler->prepareException(Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#1 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nunomaduro\/collision\/src\/Adapters\/Laravel\/ExceptionHandler.php(55): Illuminate\\Foundation\\Exceptions\\Handler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#2 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Pipeline.php(51): NunoMaduro\\Collision\\Adapters\\Laravel\\ExceptionHandler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#3 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(182): Illuminate\\Routing\\Pipeline->handleException(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#4 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Middleware\/SubstituteBindings.php(52): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#5 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(219): Illuminate\\Routing\\Middleware\\SubstituteBindings->handle(Object(Illuminate\\Http\\Request), Object(Closure))\n#6 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(137): Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}(Object(Illuminate\\Http\\Request))\n#7 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(821): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#8 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(800): Illuminate\\Routing\\Router->runRouteWithinStack(Object(Illuminate\\Routing\\Route), Object(Illuminate\\Http\\Request))\n#9 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(764): Illuminate\\Routing\\Router->runRoute(Object(Illuminate\\Http\\Request), Object(Illuminate\\Routing\\Route))\n#10 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(753): Illuminate\\Routing\\Router->dispatchToRoute(Object(Illuminate\\Http\\Request))\n#11 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Http\/Kernel.php(200): Illuminate\\Routing\\Router->dispatch(Object(Illuminate\\Http\\Request))\n#12 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(180): Illuminate\\Foundation\\Http\\Kernel->{closure:Illuminate\\Foundation\\Http\\Kernel::dispatchToRouter():197}(Object(Illuminate\\Http\\Request))\n#13 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nativephp\/mobile\/src\/Http\/Middleware\/RenderEdgeComponents.php(14): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#14 \/data\/data\/com.medicalplus.app\/app_storage\/la
08-01 17:27:27.973 32198 32198 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:28.127 32198 32207 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:28.376 12040 21924 D WearableService: onGetService: waiting for onCreate to be completed.
08-01 17:27:28.512 31903 32117 D WM-Schedulers: Created SystemJobScheduler and enabled SystemJobService
08-01 17:27:28.562 28409 28409 D PHPMonitor-Console: [SyncEngine] ⚠ GET /_native/api/sync/pending-summary failed: timeout of 5000ms exceeded (status=none) -- From line 1
08-01 17:27:28.562 28409 28409 D PHPMonitor-Console: [SyncEngine] ➖ No changes from sync, skipping patient list refresh -- From line 1
08-01 17:27:28.766 32198 32198 D LogUtils: initWirelessLog UninitializedPropertyAccessException
08-01 17:27:28.880 32269 32269 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:28.919 32269 32286 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:28.931 32269 32286 D SurfaceSyncGroup: preCreateSurfaceSyncGroupTimerThread
08-01 17:27:29.042 32198 32198 D WS_OplusWirelessSettingsApp: onCreate, OplusWirelessSettingsApp
08-01 17:27:29.117 32294 32294 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:29.219 32294 32311 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:29.545 28409 29728 E PHPBridge: {"error":true,"exception":"Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException","message":"No query results for model [App\\Domains\\Patients\\Models\\Patient].","sqlstate":null,"sqlite_error":null,"file":"\/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php","line":768,"trace":"#0 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Exceptions\/Handler.php(716): Illuminate\\Foundation\\Exceptions\\Handler->prepareException(Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#1 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nunomaduro\/collision\/src\/Adapters\/Laravel\/ExceptionHandler.php(55): Illuminate\\Foundation\\Exceptions\\Handler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#2 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Pipeline.php(51): NunoMaduro\\Collision\\Adapters\\Laravel\\ExceptionHandler->render(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#3 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(182): Illuminate\\Routing\\Pipeline->handleException(Object(Illuminate\\Http\\Request), Object(Illuminate\\Database\\Eloquent\\ModelNotFoundException))\n#4 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Middleware\/SubstituteBindings.php(52): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#5 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(219): Illuminate\\Routing\\Middleware\\SubstituteBindings->handle(Object(Illuminate\\Http\\Request), Object(Closure))\n#6 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(137): Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}(Object(Illuminate\\Http\\Request))\n#7 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(821): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#8 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(800): Illuminate\\Routing\\Router->runRouteWithinStack(Object(Illuminate\\Routing\\Route), Object(Illuminate\\Http\\Request))\n#9 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(764): Illuminate\\Routing\\Router->runRoute(Object(Illuminate\\Http\\Request), Object(Illuminate\\Routing\\Route))\n#10 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Routing\/Router.php(753): Illuminate\\Routing\\Router->dispatchToRoute(Object(Illuminate\\Http\\Request))\n#11 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Http\/Kernel.php(200): Illuminate\\Routing\\Router->dispatch(Object(Illuminate\\Http\\Request))\n#12 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/laravel\/framework\/src\/Illuminate\/Pipeline\/Pipeline.php(180): Illuminate\\Foundation\\Http\\Kernel->{closure:Illuminate\\Foundation\\Http\\Kernel::dispatchToRouter():197}(Object(Illuminate\\Http\\Request))\n#13 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/vendor\/nativephp\/mobile\/src\/Http\/Middleware\/RenderEdgeComponents.php(14): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}(Object(Illuminate\\Http\\Request))\n#14 \/data\/data\/com.medicalplus.app\/app_storage\/laravel\/v
08-01 17:27:29.752 32294 32294 W PhoneManager.GrayProductProvider: onCreate with db instance: true
08-01 17:27:29.895  3143  7737 E OplusDataSyncManagerService: Unable to updateAppData ISysStateChangeCallback for type voip_syncas no module added
08-01 17:27:29.907 32294 32337 D WM-Schedulers: Created SystemJobScheduler and enabled SystemJobService
08-01 17:27:29.923  9327  9327 D BoundBrokerSvc: onRebind: Intent { act=com.google.android.gms.appset.service.START dat=chimera-action:/... xflg=0x4 cmp=com.google.android.gms/.chimera.GmsApiService mCallingUid=10132 }
08-01 17:27:29.997 32366 32366 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:30.029 32366 32378 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:30.036 32294 32383 E MSP-LOG-SDK-: MspSdkException{code=3014,message=com.heytap.mspsdk.ratelimit.MspRateLimitExceededException: ipc rate limit exceeded in time window}
08-01 17:27:30.036 32294 32384 E MSP-LOG-SDK-: MspSdkException{code=3014,message=com.heytap.mspsdk.ratelimit.MspRateLimitExceededException: ipc rate limit exceeded in time window}
08-01 17:27:30.074 12035 32184 W PrefControllerMixin: Skipping updateNonIndexableKeys, key already in list. com.android.settings.users.AutoSyncDataPreferenceController@9fa79be
08-01 17:27:30.074 12035 32184 W PrefControllerMixin: Skipping updateNonIndexableKeys, key already in list. com.android.settings.users.AutoSyncWorkDataPreferenceController@7b1ea1f
08-01 17:27:30.131 32294 32394 E MSP-LOG-SDK-: MspSdkException{code=3014,message=com.heytap.mspsdk.ratelimit.MspRateLimitExceededException: ipc rate limit exceeded in time window}
08-01 17:27:30.132 12035 32184 W BaseSearchIndex: Error initializing controller in fragment: com.oplus.settings.feature.deviceinfo.aboutphone.DeviceInfoFragment$3@c7e8e0c, e: java.lang.RuntimeException: Can't create handler inside thread Thread[SET3T319common,5,main] that has not called Looper.prepare()
08-01 17:27:30.585 32366 32366 I NewAppEncrypt.SecureSafeApp: onCreate  Locking in the timing = true
08-01 17:27:30.611 12035 32184 E Settings_EnableVerboseVendorLoggingPreferenceController: reflect getService err java.lang.reflect.InvocationTargetException
08-01 17:27:30.611 12035 32184 E Settings_EnableVerboseVendorLoggingPreferenceController: reflect getService err java.lang.reflect.InvocationTargetException
08-01 17:27:30.820 31839 31939 I IndexDatabaseHelper: Using schema version: 122
08-01 17:27:30.824 31839 31939 I IndexDatabaseHelper: Index is fine
08-01 17:27:30.859 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.android.settings"
08-01 17:27:30.931 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.android.settings"
08-01 17:27:30.993 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.coloros.translate"
08-01 17:27:31.021 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.coloros.translate"
08-01 17:27:31.051 31839 32419 E OplusPreIndexDataCollector: get settings provider values got exception android.provider.Settings$SettingNotFoundException: content_portal_setting_switch_string
08-01 17:27:31.053 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.contentportal"
08-01 17:27:31.101 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.contentportal"
08-01 17:27:31.119 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.aimemory"
08-01 17:27:31.136 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.aimemory"
08-01 17:27:31.154 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.calendar"
08-01 17:27:31.170 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.calendar"
08-01 17:27:31.242 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.games"
08-01 17:27:31.259 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.games"
08-01 17:27:31.276 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.camera"
08-01 17:27:31.292 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.oplus.camera"
08-01 17:27:31.423 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.coloros.gallery3d"
08-01 17:27:31.439 31839 31939 W SQLiteLog: (28) double-quoted string literal: "com.coloros.gallery3d"
08-01 17:27:31.521 28903 31713 I SG.SecurityEventProvider: callSyncAdIdentifyConfigMethod config=AdConfig(enabled=false, totalLimit=1, sampleRate=0.01, maxObserveAppCount=30, maxExtractTemperature=38.0, maxExtractCpuPressureLevel=2, trustedPackages size=0)
08-01 17:27:31.704 32425 32425 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:31.739 12040 12040 D BoundBrokerSvc: onUnbind: Intent { act=com.google.android.gms.presencemanager.service.START dat=chimera-action:/... xflg=0x4 cmp=com.google.android.gms/.chimera.PersistentApiService mCallingUid=10130 }
08-01 17:27:31.742 12040 12040 D BoundBrokerSvc: onUnbind: Intent { act=com.google.android.gms.presencemanager.service.INTERNAL_IDENTITY dat=chimera-action:/... xflg=0x4 cmp=com.google.android.gms/.chimera.PersistentApiService mCallingUid=10130 }
08-01 17:27:31.752 32426 32426 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:31.753 32425 32436 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:31.794 32435 32435 D OplusActivityThreadExtImpl: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.SupressionGC [int, int]
08-01 17:27:31.802  3143  7922 E OplusDataSyncManagerService: Unable to updateAppData ISysStateChangeCallback for type fraud_detectas no module added
08-01 17:27:31.806 32426 32445 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
08-01 17:27:31.818 12040 12040 D BoundBrokerSvc: onUnbind: Intent { act=com.google.android.gms.auth.aang.events.services.START dat=chimera-action:/... xflg=0x4 cmp=com.google.android.gms/.chimera.PersistentApiService mCallingUid=10129 }
08-01 17:27:31.825 32435 32455 D OplusAppHeapManager: java.lang.NoSuchMethodException: dalvik.system.VMRuntime.updateProcessValue [int, int, int]
