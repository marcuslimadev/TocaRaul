package com.socimob.tocaraul.domain.model

data class JukeboxUiState(
    val connection: ConnectionState,
    val nowPlaying: Track?,
    val dedication: Dedication?,
    val queueSize: Int,
    val qrCodeUrl: String,
    val activationCode: String?,
    val activationUrl: String?,
    val playbackState: String
)
