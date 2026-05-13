package com.mechanix.api

import android.content.Context
import com.mechanix.api.TokenManager.accessToken
import com.mechanix.api.TokenManager.refreshToken
import com.mechanix.api.TokenManager.saveTokens
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException

object ApiClient {
    private const val BASE_URL = "https://ravendark.alwaysdata.net/api/v1/"
    private val JSON = "application/json; charset=utf-8".toMediaType()

    private val client = OkHttpClient.Builder()
        .connectTimeout(30, java.util.concurrent.TimeUnit.SECONDS)
        .readTimeout(30, java.util.concurrent.TimeUnit.SECONDS)
        .build()

    fun get(endpoint: String, authenticated: Boolean = true): Response {
        val request = Request.Builder()
            .url("$BASE_URL$endpoint")
            .apply {
                if (authenticated && accessToken.isNotEmpty()) {
                    addHeader("Authorization", "Bearer $accessToken")
                }
            }
            .get()
            .build()
        return client.newCall(request).execute()
    }

    fun post(endpoint: String, body: String, authenticated: Boolean = true): Response {
        val requestBody = body.toRequestBody(JSON)
        val request = Request.Builder()
            .url("$BASE_URL$endpoint")
            .apply {
                if (authenticated && accessToken.isNotEmpty()) {
                    addHeader("Authorization", "Bearer $accessToken")
                }
            }
            .post(requestBody)
            .build()
        return client.newCall(request).execute()
    }

    fun postForm(endpoint: String, params: Map<String, String>): Response {
        val formBody = FormBody.Builder()
            .apply { params.forEach { add(it.key, it.value) } }
            .build()
        val request = Request.Builder()
            .url("$BASE_URL$endpoint")
            .post(formBody)
            .build()
        return client.newCall(request).execute()
    }

    fun refreshAccessToken(): Boolean {
        if (refreshToken.isEmpty()) return false
        return try {
            val response = postForm("auth/refresh.php", mapOf("refresh_token" to refreshToken))
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    val item = json.optJSONObject("item")
                    if (item != null) {
                        saveTokens(
                            item.optString("access_token", ""),
                            item.optString("refresh_token", refreshToken)
                        )
                        return true
                    }
                }
            }
            false
        } catch (e: IOException) {
            false
        }
    }
}

object TokenManager {
    private const val PREFS_NAME = "mechanix_prefs"
    private const val KEY_ACCESS_TOKEN = "access_token"
    private const val KEY_REFRESH_TOKEN = "refresh_token"
    private const val KEY_USER_ROLE = "user_role"
    private const val KEY_TENANT_ID = "tenant_id"
    private const val KEY_USERNAME = "username"

    private var prefs: android.content.SharedPreferences? = null

    fun init(context: Context) {
        prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    var accessToken: String
        get() = prefs?.getString(KEY_ACCESS_TOKEN, "") ?: ""
        set(value) { prefs?.edit()?.putString(KEY_ACCESS_TOKEN, value)?.apply() }

    var refreshToken: String
        get() = prefs?.getString(KEY_REFRESH_TOKEN, "") ?: ""
        set(value) { prefs?.edit()?.putString(KEY_REFRESH_TOKEN, value)?.apply() }

    var userRole: String
        get() = prefs?.getString(KEY_USER_ROLE, "") ?: ""
        set(value) { prefs?.edit()?.putString(KEY_USER_ROLE, value)?.apply() }

    var tenantId: String
        get() = prefs?.getString(KEY_TENANT_ID, "") ?: ""
        set(value) { prefs?.edit()?.putString(KEY_TENANT_ID, value)?.apply() }

    var username: String
        get() = prefs?.getString(KEY_USERNAME, "") ?: ""
        set(value) { prefs?.edit()?.putString(KEY_USERNAME, value)?.apply() }

    fun saveTokens(access: String, refresh: String) {
        accessToken = access
        refreshToken = refresh
    }

    fun saveUserInfo(role: String, tenant: String, user: String) {
        userRole = role
        tenantId = tenant
        username = user
    }

    fun clear() {
        prefs?.edit()?.clear()?.apply()
    }

    fun isLoggedIn(): Boolean = accessToken.isNotEmpty()
}