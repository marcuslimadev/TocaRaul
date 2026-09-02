package com.socimob.tocaraul.domain.model

data class Track(
    val id: String,
    val providerId: String,
    val title: String,
    val artist: String,
    val thumbnailUrl: String?,
    val durationSeconds: Int?
)
