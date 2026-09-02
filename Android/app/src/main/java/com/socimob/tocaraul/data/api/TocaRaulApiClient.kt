package com.socimob.tocaraul.data.api

class TocaRaulApiClient(baseUrl: String) {
    private val baseUrl = baseUrl.trimEnd('/')

    fun deviceSessionUrl(): String = "$baseUrl/api/device/session"
    fun deviceActivationUrl(): String = "$baseUrl/api/device/activate"
    fun deviceHeartbeatUrl(): String = "$baseUrl/api/device/heartbeat"
    fun deviceStateUrl(): String = "$baseUrl/api/device/state"
}
