package com.socimob.tocaraul.data.repository

import com.socimob.tocaraul.data.api.TocaRaulApiClient
import com.socimob.tocaraul.domain.model.Track
import com.socimob.tocaraul.domain.repository.ClaimedTrack
import com.socimob.tocaraul.domain.repository.PlayerQueueRepository
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

class RemotePlayerQueueRepository(
    private val api: TocaRaulApiClient
) : PlayerQueueRepository {
    override suspend fun claim(deviceToken: String): ClaimedTrack? = withContext(Dispatchers.IO) {
        val response = post(api.playerClaimUrl(), JSONObject(), deviceToken)
        val item = response.optJSONObject("track") ?: return@withContext null
        ClaimedTrack(
            track = Track(
                id = item.getString("id"),
                providerId = item.getString("providerId"),
                title = item.getString("title"),
                artist = item.getString("artist"),
                thumbnailUrl = null,
                durationSeconds = null
            ),
            message = item.optString("message").takeIf { it.isNotBlank() && it != "null" },
            tableCode = item.optString("tableCode").takeIf { it.isNotBlank() && it != "null" }
        )
    }

    override suspend fun complete(deviceToken: String, requestId: String, result: String): Unit = withContext(Dispatchers.IO) {
        post(
            api.playerCompleteUrl(),
            JSONObject().put("requestId", requestId.toInt()).put("result", result),
            deviceToken
        )
    }

    private fun post(url: String, body: JSONObject, bearerToken: String): JSONObject {
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.requestMethod = "POST"
        connection.doOutput = true
        connection.connectTimeout = 12_000
        connection.readTimeout = 12_000
        connection.setRequestProperty("Accept", "application/json")
        connection.setRequestProperty("Content-Type", "application/json")
        connection.setRequestProperty("Authorization", "Bearer $bearerToken")
        OutputStreamWriter(connection.outputStream).use { it.write(body.toString()) }
        val status = connection.responseCode
        val stream = if (status in 200..299) connection.inputStream else connection.errorStream ?: connection.inputStream
        val text = stream.bufferedReader().use { it.readText() }
        if (status !in 200..299) throw IllegalStateException("TocaRaul player API failed ($status)")
        return JSONObject(text)
    }
}
