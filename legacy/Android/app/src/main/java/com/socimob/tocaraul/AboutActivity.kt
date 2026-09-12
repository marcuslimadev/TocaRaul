package com.socimob.tocaraul

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.tv.material3.ExperimentalTvMaterial3Api
import androidx.tv.material3.Text
import com.socimob.tocaraul.ui.theme.TocaRaulTheme

private val Background = Color(0xFF090909)
private val Accent = Color(0xFFFFCC00)
private val Muted = Color(0xFFCCCCCC)

class AboutActivity : ComponentActivity() {
    @OptIn(ExperimentalTvMaterial3Api::class)
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            TocaRaulTheme {
                Column(
                    Modifier.fillMaxSize().background(Background).safeDrawingPadding().padding(48.dp),
                    verticalArrangement = Arrangement.Center
                ) {
                    Image(
                        painterResource(R.drawable.tocaraul_logo), "TocaRaul",
                        Modifier.width(160.dp).height(96.dp), contentScale = ContentScale.Fit
                    )
                    Spacer(Modifier.height(24.dp))
                    Text("Sobre o TocaRaul", color = Color.White, fontSize = 32.sp, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(12.dp))
                    Text("Versão ${BuildConfig.VERSION_NAME}", color = Muted, fontSize = 18.sp)
                    Spacer(Modifier.height(8.dp))
                    Text(
                        "Música, fila de pedidos e dedicatórias na TV do seu estabelecimento.",
                        color = Muted, fontSize = 18.sp
                    )
                    Spacer(Modifier.height(28.dp))
                    Text("SUPORTE", color = Accent, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(4.dp))
                    Text("marcus.lima@hotmail.com.br", color = Color.White, fontSize = 18.sp)
                    Spacer(Modifier.height(28.dp))
                    Text("Pressione voltar para retornar à TV.", color = Muted, fontSize = 14.sp)
                }
            }
        }
    }
}
