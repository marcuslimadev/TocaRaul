package com.socimob.tocaraul

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.*
import androidx.core.view.WindowCompat
import androidx.lifecycle.lifecycleScope
import com.socimob.tocaraul.data.api.TocaRaulApiClient
import com.socimob.tocaraul.data.local.DeviceIdentityStore
import com.socimob.tocaraul.data.repository.RemoteDeviceRepository
import com.socimob.tocaraul.data.repository.RemotePlayerQueueRepository
import com.socimob.tocaraul.domain.model.ConnectionState
import com.socimob.tocaraul.domain.model.JukeboxUiState
import com.socimob.tocaraul.domain.repository.DeviceSession
import com.socimob.tocaraul.presentation.JukeboxScreen
import com.socimob.tocaraul.ui.theme.TocaRaulTheme
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class MainActivity:ComponentActivity(){
 private var pendingRequestId:String?=null;private var pendingDeviceToken:String?=null;private var queue:RemotePlayerQueueRepository?=null
 private val playback=registerForActivityResult(ActivityResultContracts.StartActivityForResult()){r->val id=pendingRequestId;val token=pendingDeviceToken;val repo=queue;if(id!=null&&token!=null&&repo!=null)lifecycleScope.launch{try{repo.complete(token,id,if(r.resultCode==RESULT_OK)"PLAYED" else "SKIPPED")}catch(_:Throwable){};pendingRequestId=null;pendingDeviceToken=null}}
 override fun onCreate(savedInstanceState:Bundle?){super.onCreate(savedInstanceState);WindowCompat.setDecorFitsSystemWindows(window,false);val api=TocaRaulApiClient(BuildConfig.TOCARAUL_API_BASE_URL);val devices=RemoteDeviceRepository(api);queue=RemotePlayerQueueRepository(api);setContent{TocaRaulTheme{var state by remember{mutableStateOf(connecting())};LaunchedEffect(Unit){val store=DeviceIdentityStore(applicationContext);var session:DeviceSession?=store.loadSession();var retry=1000L;while(true){try{if(session==null){state=connecting();session=devices.createSession("TV Principal");store.saveSession(requireNotNull(session))};val current=requireNotNull(session);val next=devices.state(current.deviceToken);retry=1000;if(next.connection==ConnectionState.WaitingActivation){state=activation(current);delay(3000);continue};if(next.connection==ConnectionState.Online){store.markActivated();state=next;try{devices.heartbeat(current.deviceToken)}catch(_:Throwable){};try{val cmd=queue?.command(current.deviceToken);if(cmd!=null&&pendingRequestId!=null){PendingPlayerCommand.set(cmd);if(cmd=="SKIP")playback.launch(Intent(this@MainActivity,PlaybackActivity::class.java).putExtra(PlaybackActivity.EXTRA_VIDEO_ID,"___________"))}}catch(_:Throwable){};if(pendingRequestId==null){try{val claimed=queue?.claim(current.deviceToken);val video=claimed?.track?.providerId?.removePrefix("youtube:");if(claimed!=null&&video!=null&&video.matches(Regex("^[A-Za-z0-9_-]{11}$"))){pendingRequestId=claimed.track.id;pendingDeviceToken=current.deviceToken;playback.launch(Intent(this@MainActivity,PlaybackActivity::class.java).putExtra(PlaybackActivity.EXTRA_VIDEO_ID,video))}else if(claimed!=null)queue?.complete(current.deviceToken,claimed.track.id,"SKIPPED")}catch(_:Throwable){}};delay(2000);continue};state=next;delay(3000)}catch(_:Throwable){state=reconnecting();delay(retry);retry=(retry*2).coerceAtMost(30000);session=store.loadSession()}}};JukeboxScreen(state)}}}
 private fun connecting()=JukeboxUiState(ConnectionState.Reconnecting,null,null,0,"",null,null,"CONNECTING")
 private fun reconnecting()=JukeboxUiState(ConnectionState.Reconnecting,null,null,0,"",null,null,"RECONNECTING")
 private fun activation(s:DeviceSession)=JukeboxUiState(ConnectionState.WaitingActivation,null,null,0,"",s.activationCode.takeIf{it.isNotBlank()}?.chunked(3)?.joinToString(" "),s.activationUrl.takeIf{it.isNotBlank()},"WAITING_ACTIVATION")
}
