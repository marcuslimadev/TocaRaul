package com.socimob.tocaraul.domain.repository

import com.socimob.tocaraul.domain.model.Track

data class ClaimedTrack(
    val track: Track,
    val message: String?,
    val tableCode: String?
)

interface PlayerQueueRepository {
    suspend fun claim(deviceToken: String): ClaimedTrack?
    suspend fun complete(deviceToken: String, requestId: String, result: String = "PLAYED")
}
