package com.mechanix.service

import com.mechanix.api.ApiClient
import org.json.JSONArray
import org.json.JSONObject

data class CustomerDashboard(
    val activeJobs: List<Job>,
    val vehicles: List<Vehicle>,
    val outstandingBalance: Double,
    val recentServices: List<ServiceRecord>
)

data class Job(
    val id: String,
    val vehicle: String,
    val plate: String,
    val status: String,
    val priority: String,
    val updatedAt: Long
)

data class Vehicle(
    val id: String,
    val make: String,
    val model: String,
    val plate: String,
    val year: Int,
    val color: String,
    val mileage: Int
)

data class ServiceRecord(
    val id: String,
    val vehicle: String,
    val completedAt: Long,
    val invoiceAmount: Double
)

data class Appointment(
    val id: String,
    val vehicleId: String,
    val vehicle: String,
    val plate: String,
    val appointmentDate: String,
    val status: String,
    val concern: String,
    val cancellationReason: String
)

data class Invoice(
    val id: String,
    val invoiceNo: String,
    val amount: Double,
    val paidAmount: Double,
    val status: String,
    val dueDate: String,
    val createdAt: Long
)

object CustomerService {

    fun getDashboard(): CustomerDashboard? {
        return try {
            val response = ApiClient.get("customer/dashboard.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    val item = json.getJSONObject("item")
                    parseDashboard(item)
                } else null
            } else null
        } catch (e: Exception) { null }
    }

    private fun parseDashboard(json: JSONObject): CustomerDashboard {
        val activeJobs = parseJobsArray(json.optJSONArray("activeRepairJobs"))
        val vehicles = parseVehiclesArray(json.optJSONArray("ownedVehicles"))
        val outstandingBalance = json.optDouble("outstandingBalance", 0.0)
        val recentServices = parseServicesArray(json.optJSONArray("recentServiceHistory"))

        return CustomerDashboard(activeJobs, vehicles, outstandingBalance, recentServices)
    }

    private fun parseJobsArray(arr: JSONArray?): List<Job> {
        val list = mutableListOf<Job>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(Job(
                    id = obj.optString("id", ""),
                    vehicle = obj.optJSONObject("vehicle")?.let { v ->
                        "${v.optString("make", "")} ${v.optString("model", "")}"
                    } ?: "",
                    plate = obj.optJSONObject("vehicle")?.optString("plate", "") ?: "",
                    status = obj.optString("status", ""),
                    priority = obj.optString("priority", "normal"),
                    updatedAt = obj.optLong("updatedAtEpochMs", 0)
                ))
            }
        }
        return list
    }

    private fun parseVehiclesArray(arr: JSONArray?): List<Vehicle> {
        val list = mutableListOf<Vehicle>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(Vehicle(
                    id = obj.optString("id", ""),
                    make = obj.optString("make", ""),
                    model = obj.optString("model", ""),
                    plate = obj.optString("plate", ""),
                    year = obj.optInt("year", 0),
                    color = obj.optString("color", ""),
                    mileage = obj.optInt("mileage", 0)
                ))
            }
        }
        return list
    }

    private fun parseServicesArray(arr: JSONArray?): List<ServiceRecord> {
        val list = mutableListOf<ServiceRecord>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(ServiceRecord(
                    id = obj.optString("id", ""),
                    vehicle = obj.optString("vehicle", ""),
                    completedAt = obj.optLong("completedAtEpochMs", 0),
                    invoiceAmount = obj.optDouble("invoiceAmount", 0.0)
                ))
            }
        }
        return list
    }

    fun getVehicles(): List<Vehicle> {
        return try {
            val response = ApiClient.get("customer/vehicles.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseVehiclesArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    fun getAppointments(): List<Appointment> {
        return try {
            val response = ApiClient.get("customer/appointments.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseAppointmentsArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    private fun parseAppointmentsArray(arr: JSONArray?): List<Appointment> {
        val list = mutableListOf<Appointment>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(Appointment(
                    id = obj.optString("id", ""),
                    vehicleId = obj.optString("vehicleId", ""),
                    vehicle = obj.optString("vehicle", ""),
                    plate = obj.optString("plate", ""),
                    appointmentDate = obj.optString("appointmentDate", ""),
                    status = obj.optString("status", ""),
                    concern = obj.optString("concern", ""),
                    cancellationReason = obj.optString("cancellationReason", "")
                ))
            }
        }
        return list
    }

    fun createAppointment(vehicleId: String, appointmentDate: String, concern: String): Boolean {
        return try {
            val body = JSONObject().apply {
                put("vehicleId", vehicleId)
                put("appointmentDate", appointmentDate)
                put("concern", concern)
            }.toString()

            val response = ApiClient.post("customer/appointments_create.php", body)
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                json.optBoolean("ok", false)
            } else false
        } catch (e: Exception) { false }
    }

    fun cancelAppointment(appointmentId: String, reason: String): Boolean {
        return try {
            val body = JSONObject().apply {
                put("appointmentId", appointmentId)
                put("reason", reason)
            }.toString()

            val response = ApiClient.post("customer/appointments_cancel.php", body)
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                json.optBoolean("ok", false)
            } else false
        } catch (e: Exception) { false }
    }

    fun getJobs(): List<Job> {
        return try {
            val response = ApiClient.get("customer/jobs.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseJobsArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    fun getInvoices(): List<Invoice> {
        return try {
            val response = ApiClient.get("customer/invoices.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseInvoicesArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    private fun parseInvoicesArray(arr: JSONArray?): List<Invoice> {
        val list = mutableListOf<Invoice>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(Invoice(
                    id = obj.optString("id", ""),
                    invoiceNo = obj.optString("invoiceNo", ""),
                    amount = obj.optDouble("amount", 0.0),
                    paidAmount = obj.optDouble("paidAmount", 0.0),
                    status = obj.optString("status", ""),
                    dueDate = obj.optString("dueDate", ""),
                    createdAt = obj.optLong("createdAtEpochMs", 0)
                ))
            }
        }
        return list
    }

    fun payInvoice(invoiceId: String, amount: Double, paymentMethod: String, referenceNo: String): Boolean {
        return try {
            val body = JSONObject().apply {
                put("invoiceId", invoiceId)
                put("amount", amount)
                put("paymentMethod", paymentMethod)
                put("referenceNo", referenceNo)
            }.toString()

            val response = ApiClient.post("customer/pay_invoice.php", body)
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                json.optBoolean("ok", false)
            } else false
        } catch (e: Exception) { false }
    }

    fun getProfile(): Map<String, String>? {
        return try {
            val response = ApiClient.get("customer/profile.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    val item = json.getJSONObject("item")
                    mapOf(
                        "displayName" to item.optString("displayName", ""),
                        "email" to item.optString("email", ""),
                        "phone" to item.optString("phone", ""),
                        "address" to item.optString("address", "")
                    )
                } else null
            } else null
        } catch (e: Exception) { null }
    }
}