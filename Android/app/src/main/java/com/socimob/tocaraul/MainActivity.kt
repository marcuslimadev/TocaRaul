package com.socimob.tocaraul

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
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

class MainActivity : ComponentActivity() {
    private var pendingRequestId: String? = null
    private var pendingDeviceToken: String? = null
    private var queueRepository: RemotePlayerQueueRepository? = null
    private val playbackLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val requestId = pendingRequestId
        val token = pendingDeviceToken
        val repository = queueRepository
        if (requestId != null && token != null && repository != null) {
            lifecycleScope.launch {
                try { repository.complete(token, requestId, if (result.resultCode == RESULT_OK) "PLAYED" else "SKIPPED") } catch (_: Throwable) {}
                pendingRequestId = null
                pendingDeviceToken = null
            }
        }
    }
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState); WindowCompat.setDecorFitsSystemWindows(window, false)
        val api=TocaRaulApiClient(BuildConfig.TOCARAUL_API_BASE_URL);val devices=RemoteDeviceRepository(api);queueRepository=RemotePlayerQueueRepository(api)
        setContent { TocaRaulTheme { var state by remember{mutableStateOf(connectingState())};LaunchedEffect(Unit){val store=DeviceIdentityStore(applicationContext);var session:DeviceSession?=store.loadSession();var retry=1_000L;while(true){try{if(session==null){state=connectingState();session=devices.createSession("TV Principal");store.saveSession(session)};val current=session;val next=devices.state(current.deviceToken);retry=1_000L;if(next.connection==ConnectionState.WaitingActivation){state=activationState(current);delay(3_000);continue};if(next.connection==ConnectionState.Online){store.markActivated();state=next;try{devices.heartbeat(current.deviceToken)}catch(_:Throwable){};if(pendingRequestId==null){try{val claimed=queueRepository?.claim(current.deviceToken);val videoId=claimed?.track?.providerId?.removePrefix("youtube:");if(claimed!=null&&videoId!=null&&videoId.matches(Regex("^[A-Za-z0-9_-]{11}$"))){pendingRequestId=claimed.track.id;pendingDeviceToken=current.deviceToken;playbackLauncher.launch(Intent(this@MainActivity,PlaybackActivity::class.java).putExtra(PlaybackActivity.EXTRA_VIDEO_ID,videoId))}else if(claimed!=null){queueRepository?.complete(current.deviceToken,claimed.track.id,"SKIPPED")}}catch(_:Throwable){}};delay(3_000);continue};state=next;delay(3_000)}catch(_:Throwable){state=reconnectingState();delay(retry);retry=(retry*2).coerceAtMost(30_000L);session=store.loadSession()}}};JukeboxScreen(state=state)}}
    }
    private fun connectingState()=JukeboxUiState(ConnectionState.Reconnecting,null,null,0,"",null,null,"CONNECTING")
    private fun reconnectingState()=JukeboxUiState(ConnectionState.Reconnecting,null,null,0,"",null,null,"RECONNECTING")
    private fun activationState(s:DeviceSession)=JukeboxUiState(ConnectionState.WaitingActivation,null,null,0,"",s.activationCode.takeIf{it.isNotBlank()}?.chunked(3)?.joinToString(" "),s.activationUrl.takeIf{it.isNotBlank()},"WAITING_ACTIVATION")
}
