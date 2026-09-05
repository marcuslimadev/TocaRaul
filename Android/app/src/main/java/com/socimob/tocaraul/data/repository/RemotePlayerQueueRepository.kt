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

class RemotePlayerQueueRepository(private val api: TocaRaulApiClient) : PlayerQueueRepository {
    override suspend fun claim(deviceToken: String): ClaimedTrack? = withContext(Dispatchers.IO) {
        val item=post(api.playerClaimUrl(),JSONObject(),deviceToken).optJSONObject("track")?:return@withContext null
        ClaimedTrack(Track(item.getString("id"),item.getString("providerId"),item.getString("title"),item.getString("artist"),null,null),item.optString("message").takeIf{it.isNotBlank()&&it!="null"},item.optString("tableCode").takeIf{it.isNotBlank()&&it!="null"})
    }
    override suspend fun complete(deviceToken:String,requestId:String,result:String):Unit=withContext(Dispatchers.IO){post(api.playerCompleteUrl(),JSONObject().put("requestId",requestId.toInt()).put("result",result),deviceToken);Unit}
    suspend fun command(deviceToken:String):String?=withContext(Dispatchers.IO){post(api.playerCommandUrl(),JSONObject(),deviceToken).optString("command").takeIf{it.isNotBlank()&&it!="null"}}
    private fun post(url:String,body:JSONObject,bearerToken:String):JSONObject{val c=URL(url).openConnection() as HttpURLConnection;c.requestMethod="POST";c.doOutput=true;c.connectTimeout=12000;c.readTimeout=12000;c.setRequestProperty("Accept","application/json");c.setRequestProperty("Content-Type","application/json");c.setRequestProperty("Authorization","Bearer $bearerToken");OutputStreamWriter(c.outputStream).use{it.write(body.toString())};val s=c.responseCode;val stream=if(s in 200..299)c.inputStream else c.errorStream?:c.inputStream;val text=stream.bufferedReader().use{it.readText()};if(s !in 200..299)throw IllegalStateException("TocaRaul player API failed ($s)");return JSONObject(text)}
}
