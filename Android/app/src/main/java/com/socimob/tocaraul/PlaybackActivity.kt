package com.socimob.tocaraul

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.JavascriptInterface
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity

class PlaybackActivity : ComponentActivity() {
    private lateinit var webView: WebView
    @SuppressLint("SetJavaScriptEnabled") override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val videoId=intent.getStringExtra(EXTRA_VIDEO_ID).orEmpty()
        if(!videoId.matches(Regex("^[A-Za-z0-9_-]{11}$"))){setResult(RESULT_CANCELED);finish();return}
        webView=WebView(this);webView.settings.javaScriptEnabled=true;webView.settings.domStorageEnabled=true;webView.settings.mediaPlaybackRequiresUserGesture=false;webView.settings.mixedContentMode=WebSettings.MIXED_CONTENT_NEVER_ALLOW;webView.webChromeClient=WebChromeClient();webView.webViewClient=WebViewClient();webView.addJavascriptInterface(PlayerBridge(),"TocaRaul");setContentView(webView)
        val html="""<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body,#player{margin:0;width:100%;height:100%;background:#000;overflow:hidden}</style></head><body><div id="player"></div><script src="https://www.youtube.com/iframe_api"></script><script>var player;function onYouTubeIframeAPIReady(){player=new YT.Player('player',{width:'100%',height:'100%',videoId:'$videoId',playerVars:{autoplay:1,controls:1,rel:0,playsinline:1},events:{onReady:function(e){e.target.playVideo()},onStateChange:function(e){if(e.data===YT.PlayerState.ENDED){TocaRaul.finished()}}}})}function tocaRaulCommand(c){if(!player)return;if(c==='PLAY')player.playVideo();if(c==='PAUSE')player.pauseVideo();if(c==='SKIP')TocaRaul.skipped();}</script></body></html>"""
        webView.loadDataWithBaseURL("https://www.youtube.com",html,"text/html","UTF-8",null)
    }
    override fun onResume(){super.onResume();consumePendingCommand()}
    private fun consumePendingCommand(){val cmd=PendingPlayerCommand.take()?:return;webView.evaluateJavascript("tocaRaulCommand('${cmd.replace("'","")}')",null)}
    override fun onDestroy(){if(::webView.isInitialized)webView.destroy();super.onDestroy()}
    inner class PlayerBridge{@JavascriptInterface fun finished(){runOnUiThread{setResult(RESULT_OK);finish()}};@JavascriptInterface fun skipped(){runOnUiThread{setResult(RESULT_CANCELED);finish()}}}
    companion object{const val EXTRA_VIDEO_ID="video_id"}
}
object PendingPlayerCommand{private var command:String?=null;@Synchronized fun set(v:String){command=v};@Synchronized fun take():String?{val v=command;command=null;return v}}
