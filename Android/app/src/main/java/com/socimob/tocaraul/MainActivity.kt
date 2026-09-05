package com.socimob.tocaraul

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.core.view.WindowCompat
import com.socimob.tocaraul.data.api.TocaRaulApiClient
import com.socimob.tocaraul.data.local.DeviceIdentityStore
import com.socimob.tocaraul.data.repository.RemoteDeviceRepository
import com.socimob.tocaraul.domain.model.ConnectionState
import com.socimob.tocaraul.domain.model.JukeboxUiState
import com.socimob.tocaraul.domain.repository.DeviceSession
import com.socimob.tocaraul.presentation.JukeboxScreen
import com.socimob.tocaraul.ui.theme.TocaRaulTheme
import kotlinx.coroutines.delay

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window, false)

        setContent {
            TocaRaulTheme {
                var state by remember { mutableStateOf(connectingState()) }

                LaunchedEffect(Unit) {
                    val repository = RemoteDeviceRepository(TocaRaulApiClient(BuildConfig.TOCARAUL_API_BASE_URL))
                    val identityStore = DeviceIdentityStore(applicationContext)
                    var session: DeviceSession? = identityStore.loadSession()
                    var retryDelayMs = 1_000L

                    while (true) {
                        try {
                            if (session == null) {
                                state = connectingState()
                                session = repository.createSession("TV Principal")
                                identityStore.saveSession(session)
                            }

                            val currentSession = session
                            val nextState = repository.state(currentSession.deviceToken)
                            retryDelayMs = 1_000L

                            if (nextState.connection == ConnectionState.WaitingActivation) {
                                state = activationState(currentSession)
                                delay(3_000)
                                continue
                            }

                            if (nextState.connection == ConnectionState.Online) {
                                identityStore.markActivated()
                                state = nextState
                                try {
                                    repository.heartbeat(currentSession.deviceToken)
                                } catch (_: Throwable) {
                                    // State polling below is authoritative; a transient heartbeat failure must not reset the TV.
                                }
                                delay(3_000)
                                continue
                            }

                            state = nextState
                            delay(3_000)
                        } catch (_: Throwable) {
                            state = reconnectingState()
                            delay(retryDelayMs)
                            retryDelayMs = (retryDelayMs * 2).coerceAtMost(30_000L)
                            session = identityStore.loadSession()
                        }
                    }
                }

                JukeboxScreen(state = state)
            }
        }
    }

    private fun connectingState() = JukeboxUiState(
        connection = ConnectionState.Reconnecting,
        nowPlaying = null,
        dedication = null,
        queueSize = 0,
        qrCodeUrl = "",
        activationCode = null,
        activationUrl = null,
        playbackState = "CONNECTING"
    )

    private fun reconnectingState() = JukeboxUiState(
        connection = ConnectionState.Reconnecting,
        nowPlaying = null,
        dedication = null,
        queueSize = 0,
        qrCodeUrl = "",
        activationCode = null,
        activationUrl = null,
        playbackState = "RECONNECTING"
    )

    private fun activationState(session: DeviceSession) = JukeboxUiState(
        connection = ConnectionState.WaitingActivation,
        nowPlaying = null,
        dedication = null,
        queueSize = 0,
        qrCodeUrl = "",
        activationCode = session.activationCode.takeIf { it.isNotBlank() }?.chunked(3)?.joinToString(" "),
        activationUrl = session.activationUrl.takeIf { it.isNotBlank() },
        playbackState = "WAITING_ACTIVATION"
    )
}
