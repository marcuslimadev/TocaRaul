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
import com.socimob.tocaraul.BuildConfig
import com.socimob.tocaraul.data.api.TocaRaulApiClient
import com.socimob.tocaraul.data.repository.RemoteDeviceRepository
import com.socimob.tocaraul.domain.model.ConnectionState
import com.socimob.tocaraul.domain.model.JukeboxUiState
import com.socimob.tocaraul.presentation.JukeboxScreen
import com.socimob.tocaraul.ui.theme.TocaRaulTheme
import kotlinx.coroutines.delay

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window, false)
        setContent {
            TocaRaulTheme {
                var state by remember {
                    mutableStateOf(
                        JukeboxUiState(
                            connection = ConnectionState.Reconnecting,
                            nowPlaying = null,
                            dedication = null,
                            queueSize = 0,
                            qrCodeUrl = "",
                            activationCode = null,
                            activationUrl = null,
                            playbackState = "CONNECTING"
                        )
                    )
                }
                LaunchedEffect(Unit) {
                    try {
                        val repository = RemoteDeviceRepository(TocaRaulApiClient(BuildConfig.TOCARAUL_API_BASE_URL))
                        val session = repository.createSession("TV Principal")
                        val activationState = JukeboxUiState(
                            connection = ConnectionState.WaitingActivation,
                            nowPlaying = null,
                            dedication = null,
                            queueSize = 0,
                            qrCodeUrl = "",
                            activationCode = session.activationCode.chunked(3).joinToString(" "),
                            activationUrl = session.activationUrl,
                            playbackState = "WAITING_ACTIVATION"
                        )
                        state = activationState

                        while (true) {
                            delay(3_000)
                            val nextState = repository.state(session.deviceToken)
                            state = if (nextState.connection == ConnectionState.WaitingActivation) {
                                activationState
                            } else {
                                nextState
                            }
                            if (nextState.connection == ConnectionState.Online) {
                                while (true) {
                                    delay(10_000)
                                    repository.heartbeat(session.deviceToken)
                                    state = repository.state(session.deviceToken)
                                }
                            }
                        }
                    } catch (_: Throwable) {
                        state = JukeboxUiState(
                            connection = ConnectionState.Offline("Nao foi possivel conectar ao TocaRaul"),
                            nowPlaying = null,
                            dedication = null,
                            queueSize = 0,
                            qrCodeUrl = "",
                            activationCode = null,
                            activationUrl = null,
                            playbackState = "OFFLINE"
                        )
                    }
                }
                JukeboxScreen(state = state)
            }
        }
    }
}
