package com.socimob.tocaraul.domain.player

import com.socimob.tocaraul.domain.model.Track

interface PlayerEngine {
    suspend fun load(track: Track)
    suspend fun play()
    suspend fun pause()
    suspend fun stop()
}
