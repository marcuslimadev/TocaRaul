package com.socimob.tocaraul.domain.model

sealed interface ConnectionState {
    data object WaitingActivation : ConnectionState
    data object Online : ConnectionState
    data object Reconnecting : ConnectionState
    data class Offline(val reason: String? = null) : ConnectionState
}
