package com.socimob.tocaraul.data.repository

import com.socimob.tocaraul.data.api.TocaRaulApiClient
import com.socimob.tocaraul.domain.model.ConnectionState
import com.socimob.tocaraul.domain.model.Track
import com.socimob.tocaraul.domain.model.JukeboxUiState
import com.socimob.tocaraul.domain.repository.ActivationResult
import com.socimob.tocaraul.domain.repository.DeviceRepository
import com.socimob.tocaraul.domain.repository.DeviceSession
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

class RemoteDeviceRepository(
    private val api: TocaRaulApiClient
) : DeviceRepository {
    override suspend fun createSession(deviceName: String): DeviceSession = withContext(Dispatchers.IO) {
        val json = post(api.deviceSessionUrl(), JSONObject().put("name", deviceName))
        DeviceSession(
            deviceId = json.getInt("deviceId"),
            deviceToken = json.getString("deviceToken"),
            activationCode = json.getString("activationCode"),
            activationUrl = json.getString("activationUrl"),
            expiresAt = json.getString("expiresAt")
        )
    }

    override suspend fun activate(code: String): ActivationResult = withContext(Dispatchers.IO) {
        val json = post(api.deviceActivationUrl(), JSONObject().put("activationCode", code))
        val venue = json.getJSONObject("venue")
        ActivationResult(
            deviceToken = json.getString("deviceToken"),
            venueName = venue.getString("name")
        )
    }

    override suspend fun heartbeat(deviceToken: String): Unit = withContext(Dispatchers.IO) {
        post(api.deviceHeartbeatUrl(), JSONObject(), deviceToken)
    }

    override suspend fun state(deviceToken: String): JukeboxUiState = withContext(Dispatchers.IO) {
        val json = get(api.deviceStateUrl(), deviceToken)
        if (json.optString("connection") == "WAITING_ACTIVATION") {
            JukeboxUiState(
                connection = ConnectionState.WaitingActivation,
                nowPlaying = null,
                dedication = null,
                queueSize = 0,
                qrCodeUrl = "",
                activationCode = null,
                activationUrl = null,
                playbackState = "WAITING_ACTIVATION"
            )
        } else {
            val nowPlayingJson = json.optJSONObject("nowPlaying")
            JukeboxUiState(
                connection = ConnectionState.Online,
                nowPlaying = nowPlayingJson?.let {
                    Track(
                        id = it.getString("id"),
                        providerId = it.getString("providerId"),
                        title = it.getString("title"),
                        artist = it.getString("artist"),
                        thumbnailUrl = null,
                        durationSeconds = null
                    )
                },
                dedication = null,
                queueSize = json.optInt("queueSize", 0),
                qrCodeUrl = json.optString("qrCodeUrl", ""),
                activationCode = null,
                activationUrl = null,
                playbackState = json.optString("playbackState", "IDLE")
            )
        }
    }

    private fun get(url: String, bearerToken: String? = null): JSONObject {
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.requestMethod = "GET"
        connection.connectTimeout = 12_000
        connection.readTimeout = 12_000
        connection.setRequestProperty("Accept", "application/json")
        if (!bearerToken.isNullOrBlank()) connection.setRequestProperty("Authorization", "Bearer $bearerToken")
        return readJson(connection)
    }

    private fun post(url: String, body: JSONObject, bearerToken: String? = null): JSONObject {
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.requestMethod = "POST"
        connection.doOutput = true
        connection.connectTimeout = 12_000
        connection.readTimeout = 12_000
        connection.setRequestProperty("Accept", "application/json")
        connection.setRequestProperty("Content-Type", "application/json")
        if (!bearerToken.isNullOrBlank()) connection.setRequestProperty("Authorization", "Bearer $bearerToken")
        OutputStreamWriter(connection.outputStream).use { it.write(body.toString()) }
        return readJson(connection)
    }

    private fun readJson(connection: HttpURLConnection): JSONObject {
        val status = connection.responseCode
        val stream = if (status in 200..299) connection.inputStream else connection.errorStream ?: connection.inputStream
        val text = stream.bufferedReader().use { it.readText() }
        if (status !in 200..299) throw IllegalStateException("TocaRaul API failed ($status)")
        return JSONObject(text)
    }
}
