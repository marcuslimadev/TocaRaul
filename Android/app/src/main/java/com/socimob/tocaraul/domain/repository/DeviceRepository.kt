package com.socimob.tocaraul.domain.repository

import com.socimob.tocaraul.domain.model.JukeboxUiState

interface DeviceRepository {
    suspend fun createSession(deviceName: String): DeviceSession
    suspend fun activate(code: String): ActivationResult
    suspend fun heartbeat(deviceToken: String)
    suspend fun state(deviceToken: String): JukeboxUiState
}

data class DeviceSession(
    val deviceId: Int,
    val deviceToken: String,
    val activationCode: String,
    val activationUrl: String,
    val expiresAt: String
)

data class ActivationResult(
    val deviceToken: String,
    val venueName: String
)
