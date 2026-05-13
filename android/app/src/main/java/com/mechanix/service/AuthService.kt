package com.mechanix.service

import com.mechanix.api.ApiClient
import com.mechanix.api.TokenManager
import org.json.JSONObject

data class LoginResult(
    val success: Boolean,
    val error: String? = null,
    val userRole: String = "",
    val businessName: String = ""
)

data class AuthTokens(
    val accessToken: String,
    val refreshToken: String
)

object AuthService {
    fun login(username: String, password: String): LoginResult {
        return try {
            val response = ApiClient.postForm("auth/login.php", mapOf(
                "username" to username,
                "password" to password
            ))

            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    val item = json.getJSONObject("item")
                    val tokens = item.getJSONObject("tokens")

                    TokenManager.saveTokens(
                        tokens.getString("access_token"),
                        tokens.getString("refresh_token")
                    )

                    TokenManager.saveUserInfo(
                        item.optString("role", ""),
                        item.optString("tenant_id", ""),
                        item.optString("username", "")
                    )

                    LoginResult(
                        success = true,
                        userRole = item.optString("role", ""),
                        businessName = item.optString("business_name", "")
                    )
                } else {
                    val error = json.optJSONObject("error")
                    LoginResult(false, error?.optString("message", "Login failed"))
                }
            } else {
                LoginResult(false, "Server error: ${response.code}")
            }
        } catch (e: Exception) {
            LoginResult(false, e.message ?: "Network error")
        }
    }

    fun logout() {
        TokenManager.clear()
    }

    fun isLoggedIn(): Boolean = TokenManager.isLoggedIn()

    fun getUserRole(): String = TokenManager.userRole
}