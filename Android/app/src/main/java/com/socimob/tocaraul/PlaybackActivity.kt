package com.socimob.tocaraul

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.*
import androidx.activity.ComponentActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.*
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

class PlaybackActivity:ComponentActivity(){
 private lateinit var webView:WebView;private var pollJob:Job?=null
 @SuppressLint("SetJavaScriptEnabled") override fun onCreate(savedInstanceState:Bundle?){super.onCreate(savedInstanceState);val video=intent.getStringExtra(EXTRA_VIDEO_ID).orEmpty();val token=intent.getStringExtra(EXTRA_DEVICE_TOKEN).orEmpty();val commandUrl=intent.getStringExtra(EXTRA_COMMAND_URL).orEmpty();if(!video.matches(Regex("^[A-Za-z0-9_-]{11}$"))){setResult(RESULT_CANCELED);finish();return};webView=WebView(this);webView.settings.javaScriptEnabled=true;webView.settings.domStorageEnabled=true;webView.settings.mediaPlaybackRequiresUserGesture=false;webView.settings.mixedContentMode=WebSettings.MIXED_CONTENT_NEVER_ALLOW;webView.webChromeClient=WebChromeClient();webView.webViewClient=WebViewClient();webView.addJavascriptInterface(Bridge(),"TocaRaul");setContentView(webView);val html="""<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body,#player{margin:0;width:100%;height:100%;background:#000;overflow:hidden}</style></head><body><div id="player"></div><script src="https://www.youtube.com/iframe_api"></script><script>var player;function onYouTubeIframeAPIReady(){player=new YT.Player('player',{width:'100%',height:'100%',videoId:'$video',playerVars:{autoplay:1,controls:1,rel:0,playsinline:1},events:{onReady:e=>e.target.playVideo(),onStateChange:e=>{if(e.data===YT.PlayerState.ENDED)TocaRaul.finished()}}})}function cmd(c){if(!player)return;if(c==='PLAY')player.playVideo();if(c==='PAUSE')player.pauseVideo();if(c==='SKIP')TocaRaul.skipped()}</script></body></html>""";webView.loadDataWithBaseURL("https://www.youtube.com",html,"text/html","UTF-8",null);if(token.isNotBlank()&&commandUrl.isNotBlank())pollJob=lifecycleScope.launch{while(isActive){try{val c=withContext(Dispatchers.IO){poll(commandUrl,token)};if(c!=null)webView.evaluateJavascript("cmd('$c')",null)}catch(_:Throwable){};delay(1500)}}}
 private fun poll(url:String,token:String):String?{val c=URL(url).openConnection() as HttpURLConnection;c.requestMethod="POST";c.doOutput=true;c.connectTimeout=6000;c.readTimeout=6000;c.setRequestProperty("Authorization","Bearer $token");c.setRequestProperty("Content-Type","application/json");OutputStreamWriter(c.outputStream).use{it.write("{}")};if(c.responseCode !in 200..299)return null;val j=JSONObject(c.inputStream.bufferedReader().use{it.readText()});return j.optString("command").takeIf{it in listOf("PLAY","PAUSE","SKIP")}}
 override fun onDestroy(){pollJob?.cancel();if(::webView.isInitialized)webView.destroy();super.onDestroy()}
 inner class Bridge{@JavascriptInterface fun finished(){runOnUiThread{setResult(RESULT_OK);finish()}};@JavascriptInterface fun skipped(){runOnUiThread{setResult(RESULT_CANCELED);finish()}}}
 companion object{const val EXTRA_VIDEO_ID="video_id";const val EXTRA_DEVICE_TOKEN="device_token";const val EXTRA_COMMAND_URL="command_url"}
}
