package com.socimob.tocaraul.presentation

import android.graphics.Bitmap
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.tv.material3.ExperimentalTvMaterial3Api
import androidx.tv.material3.Text
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel
import com.socimob.tocaraul.R
import com.socimob.tocaraul.domain.model.ConnectionState
import com.socimob.tocaraul.domain.model.JukeboxUiState

private val Background = Color(0xFF050505)
private val Panel = Color(0xE6111111)
private val PanelSoft = Color(0xFF171717)
private val Accent = Color(0xFFFFCC00)
private val AccentSoft = Color(0x66FFCC00)
private val Muted = Color(0xFFD8D3C7)

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
fun JukeboxScreen(state: JukeboxUiState) {
    when (state.connection) {
        ConnectionState.WaitingActivation -> ActivationScreen(state)
        ConnectionState.Online -> PlayerScreen(state)
        ConnectionState.Reconnecting -> OfflineScreen("Reconectando...")
        is ConnectionState.Offline -> OfflineScreen(state.connection.reason ?: "Conexao perdida")
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun ActivationScreen(state: JukeboxUiState) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Background)
            .padding(horizontal = 70.dp, vertical = 36.dp),
        contentAlignment = Alignment.Center
    ) {
        Column(
            modifier = Modifier.fillMaxWidth(),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            TocaRaulLogo(230)
            Spacer(Modifier.height(18.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(34.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Column(
                    modifier = Modifier.weight(1f),
                    horizontalAlignment = Alignment.Start
                ) {
                    Text("Configure esta TV", color = Color.White, fontSize = 42.sp, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(14.dp))
                    Text(
                        "Escaneie o QR Code para criar seu login, cadastrar o bar e vincular esta tela ao TocaRaul.",
                        color = Muted,
                        fontSize = 22.sp,
                        lineHeight = 30.sp
                    )
                    Spacer(Modifier.height(24.dp))
                    Text("CODIGO DA TV", color = Accent, fontSize = 16.sp, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(10.dp))
                    Box(
                        modifier = Modifier
                            .clip(RoundedCornerShape(22.dp))
                            .background(Panel)
                            .border(BorderStroke(1.dp, Accent), RoundedCornerShape(22.dp))
                            .padding(horizontal = 44.dp, vertical = 20.dp)
                    ) {
                        Text(state.activationCode ?: "--- ---", color = Accent, fontSize = 48.sp, fontWeight = FontWeight.Bold)
                    }
                    Spacer(Modifier.height(18.dp))
                    Text("Depois da configuracao, a TV entra automaticamente no player.", color = Muted, fontSize = 18.sp)
                }
                ActivationQrCard(state.activationUrl ?: "https://tocaraul.app/ativar")
            }
        }
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun ActivationQrCard(url: String) {
    Column(
        modifier = Modifier
            .width(470.dp)
            .clip(RoundedCornerShape(28.dp))
            .background(Panel)
            .border(BorderStroke(1.dp, Accent), RoundedCornerShape(28.dp))
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text("Aponte a camera", color = Color.White, fontSize = 25.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
        Text("do celular", color = Color.White, fontSize = 23.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
        Spacer(Modifier.height(16.dp))
        QrCodeImage(url = url, size = 380)
        Spacer(Modifier.height(12.dp))
        Text(url.removePrefix("https://"), color = Muted, fontSize = 12.sp, textAlign = TextAlign.Center, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun PlayerScreen(state: JukeboxUiState) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Background)
            .padding(horizontal = 38.dp, vertical = 18.dp)
    ) {
        Column(
            modifier = Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.SpaceBetween
        ) {
            Header()
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(18.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Column(
                    modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(14.dp)
                ) {
                    NowPlayingCard(state)
                    QueueCard()
                }
                QrCard(state.qrCodeUrl)
            }
            Footer()
        }
    }
}

@Composable
private fun Header() {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        VenuePill("Bar do Centro")
        TocaRaulLogo(210)
        VenuePill("Mesa 08")
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun VenuePill(text: String) {
    Box(
        modifier = Modifier
            .clip(RoundedCornerShape(18.dp))
            .background(Panel)
            .border(BorderStroke(1.dp, AccentSoft), RoundedCornerShape(18.dp))
            .padding(horizontal = 24.dp, vertical = 14.dp)
    ) {
        Text(text, color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun NowPlayingCard(state: JukeboxUiState) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(168.dp)
            .clip(RoundedCornerShape(22.dp))
            .background(Panel)
            .border(BorderStroke(1.dp, Accent), RoundedCornerShape(22.dp))
            .padding(18.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        CoverArt()
        Spacer(Modifier.width(22.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text("TOCANDO AGORA", color = Accent, fontSize = 13.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            Text(
                state.nowPlaying?.title ?: "Aguardando pedidos",
                color = Color.White,
                fontSize = 34.sp,
                fontWeight = FontWeight.Bold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis
            )
            Text(
                state.nowPlaying?.artist ?: "Escaneie o QR Code para escolher a proxima musica",
                color = Accent,
                fontSize = 22.sp,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis
            )
            Spacer(Modifier.height(14.dp))
            ProgressBar()
        }
    }
}

@Composable
private fun CoverArt() {
    Box(
        modifier = Modifier
            .size(132.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(Color(0xFF251441)),
        contentAlignment = Alignment.Center
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color(0xFF2C1848))
        )
        Text("♪", color = Accent, fontSize = 54.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun ProgressBar() {
    Row(verticalAlignment = Alignment.CenterVertically) {
        TimeText("01:24")
        Spacer(Modifier.width(16.dp))
        Box(
            modifier = Modifier
                .height(10.dp)
                .weight(1f)
                .clip(RoundedCornerShape(50))
                .background(Color(0xFF3A3A3A))
        ) {
            Box(
                modifier = Modifier
                    .fillMaxWidth(0.42f)
                    .height(10.dp)
                    .clip(RoundedCornerShape(50))
                    .background(Accent)
            )
        }
        Spacer(Modifier.width(16.dp))
        TimeText("03:45")
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun TimeText(text: String) {
    Text(text, color = Color.White, fontSize = 18.sp)
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun QueueCard() {
    Column(
            modifier = Modifier
            .fillMaxWidth()
            .height(106.dp)
            .clip(RoundedCornerShape(20.dp))
            .background(Panel)
            .border(BorderStroke(1.dp, Color(0xFF2F2A20)), RoundedCornerShape(20.dp))
            .padding(16.dp)
    ) {
        Text("♪ PROXIMAS NA FILA", color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            QueueItem(Modifier.weight(1f), 1, "Vento no Cabelo", "Os Paralelos")
            QueueItem(Modifier.weight(1f), 2, "Estrada Serena", "Lucas Marinho")
            QueueItem(Modifier.weight(1f), 3, "Enquanto Houver Luz", "Bella Aurora")
        }
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun QueueItem(modifier: Modifier, position: Int, title: String, artist: String) {
    Row(
        modifier = modifier
            .clip(RoundedCornerShape(16.dp))
            .background(PanelSoft)
            .border(BorderStroke(1.dp, Color(0xFF36302A)), RoundedCornerShape(16.dp))
            .padding(10.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(position.toString(), color = Accent, fontSize = 27.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.width(10.dp))
        Column {
            Text(title, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(artist, color = Accent, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun QrCard(qrCodeUrl: String) {
    Column(
        modifier = Modifier
            .width(245.dp)
            .height(288.dp)
            .clip(RoundedCornerShape(22.dp))
            .background(Panel)
            .border(BorderStroke(1.dp, Accent), RoundedCornerShape(22.dp))
            .padding(18.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Text("Escaneie o QR", color = Color.White, fontSize = 21.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
        Text("da sua mesa", color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
        Text("e escolha a proxima musica", color = Accent, fontSize = 15.sp, textAlign = TextAlign.Center)
        Spacer(Modifier.height(14.dp))
        if (qrCodeUrl.isBlank()) {
            QrPlaceholder(size = 116, label = "QR")
        } else {
            QrCodeImage(url = qrCodeUrl, size = 116)
        }
        Spacer(Modifier.height(10.dp))
        Text(qrCodeUrl.removePrefix("https://"), color = Muted, fontSize = 10.sp, textAlign = TextAlign.Center)
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun Footer() {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center
    ) {
        Text("☆", color = Accent, fontSize = 26.sp)
        Spacer(Modifier.width(14.dp))
        Text("Pediu.", color = Color.White, fontSize = 25.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.width(8.dp))
        Text("Tocou.", color = Accent, fontSize = 25.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun TocaRaulLogo(width: Int) {
    Image(
        painter = painterResource(id = R.drawable.tocaraul_logo),
        contentDescription = "TocaRaul",
        modifier = Modifier.width(width.dp),
        contentScale = ContentScale.FillWidth
    )
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun OfflineScreen(message: String) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Background)
            .padding(72.dp),
        contentAlignment = Alignment.Center
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            TocaRaulLogo(230)
            Spacer(Modifier.height(24.dp))
            Text(
                message,
                color = Color.White,
                fontSize = 30.sp,
                lineHeight = 36.sp,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis
            )
            Spacer(Modifier.height(16.dp))
            Text("A TV vai sincronizar automaticamente quando a internet voltar.", color = Muted, fontSize = 18.sp, textAlign = TextAlign.Center)
        }
    }
}

@Composable
private fun QrPlaceholder(size: Int, label: String) {
    Box(
        modifier = Modifier
            .size(size.dp)
            .clip(RoundedCornerShape(10.dp))
            .background(Color.White),
        contentAlignment = Alignment.Center
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(label, color = Background, fontSize = if (size > 160) 34.sp else 26.sp, fontWeight = FontWeight.Bold)
            Text("TocaRaul", color = Background, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun QrCodeImage(url: String, size: Int) {
    val bitmap = remember(url) { createQrBitmap(url, 720) }
    Image(
        bitmap = bitmap.asImageBitmap(),
        contentDescription = "QR Code",
        modifier = Modifier
            .size(size.dp)
            .clip(RoundedCornerShape(10.dp))
            .background(Color.White),
        contentScale = ContentScale.Fit
    )
}

private fun createQrBitmap(value: String, size: Int): Bitmap {
    val hints = mapOf(
        EncodeHintType.CHARACTER_SET to "UTF-8",
        EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M,
        EncodeHintType.MARGIN to 1
    )
    val matrix = QRCodeWriter().encode(value, BarcodeFormat.QR_CODE, size, size, hints)
    val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
    for (x in 0 until size) {
        for (y in 0 until size) {
            bitmap.setPixel(
                x,
                y,
                if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE
            )
        }
    }
    return bitmap
}
