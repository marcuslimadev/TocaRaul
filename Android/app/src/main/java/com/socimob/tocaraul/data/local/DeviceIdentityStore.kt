package com.socimob.tocaraul.data.local

import android.content.Context
import com.socimob.tocaraul.domain.repository.DeviceSession

class DeviceIdentityStore(context: Context) {
    private val preferences = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE)

    fun loadSession(): DeviceSession? {
        val deviceId = preferences.getInt(KEY_DEVICE_ID, 0)
        val deviceToken = preferences.getString(KEY_DEVICE_TOKEN, null).orEmpty()
        if (deviceId <= 0 || deviceToken.isBlank()) return null

        return DeviceSession(
            deviceId = deviceId,
            deviceToken = deviceToken,
            activationCode = preferences.getString(KEY_ACTIVATION_CODE, null).orEmpty(),
            activationUrl = preferences.getString(KEY_ACTIVATION_URL, null).orEmpty(),
            expiresAt = preferences.getString(KEY_EXPIRES_AT, null).orEmpty()
        )
    }

    fun saveSession(session: DeviceSession) {
        preferences.edit()
            .putInt(KEY_DEVICE_ID, session.deviceId)
            .putString(KEY_DEVICE_TOKEN, session.deviceToken)
            .putString(KEY_ACTIVATION_CODE, session.activationCode)
            .putString(KEY_ACTIVATION_URL, session.activationUrl)
            .putString(KEY_EXPIRES_AT, session.expiresAt)
            .apply()
    }

    fun markActivated() {
        preferences.edit()
            .remove(KEY_ACTIVATION_CODE)
            .remove(KEY_ACTIVATION_URL)
            .remove(KEY_EXPIRES_AT)
            .apply()
    }

    fun clear() {
        preferences.edit().clear().apply()
    }

    companion object {
        private const val PREFERENCES_NAME = "tocaraul_device_identity"
        private const val KEY_DEVICE_ID = "device_id"
        private const val KEY_DEVICE_TOKEN = "device_token"
        private const val KEY_ACTIVATION_CODE = "activation_code"
        private const val KEY_ACTIVATION_URL = "activation_url"
        private const val KEY_EXPIRES_AT = "expires_at"
    }
}
