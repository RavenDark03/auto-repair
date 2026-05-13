package com.mechanix.service

import com.mechanix.api.ApiClient
import org.json.JSONArray
import org.json.JSONObject

data class MechanicDashboard(
    val assignedJobs: List<MechanicJob>,
    val scheduledAppointments: List<MechanicAppointment>,
    val ongoingJobs: List<MechanicJob>,
    val completedToday: Int
)

data class MechanicJob(
    val id: String,
    val customerName: String,
    val vehicle: String,
    val plate: String,
    val status: String,
    val priority: String,
    val description: String,
    val concern: String,
    val mechanicId: String?,
    val mechanicName: String,
    val appointmentId: String?,
    val appointmentDate: String,
    val hasInvoice: Boolean,
    val updatedAt: Long
)

data class MechanicAppointment(
    val id: String,
    val vehicle: String,
    val plate: String,
    val appointmentDate: String,
    val concern: String,
    val status: String
)

data class JobNote(
    val id: String,
    val note: String,
    val noteType: String,
    val isCustomerVisible: Boolean,
    val createdAt: Long
)

data class JobPart(
    val id: String,
    val inventoryId: String,
    val partName: String,
    val quantity: Int,
    val unitPrice: Double,
    val createdAt: Long
)

object MechanicService {

    fun getDashboard(): MechanicDashboard? {
        return try {
            val response = ApiClient.get("mechanic/dashboard.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseDashboard(json.getJSONObject("item"))
                } else null
            } else null
        } catch (e: Exception) { null }
    }

    private fun parseDashboard(json: JSONObject): MechanicDashboard {
        val assignedJobs = parseMechanicJobsArray(json.optJSONArray("assignedJobs"))
        val scheduledAppointments = parseAppointmentsArray(json.optJSONArray("scheduledAppointments"))
        val ongoingJobs = parseMechanicJobsArray(json.optJSONArray("ongoingJobs"))
        val completedToday = json.optInt("completedToday", 0)

        return MechanicDashboard(assignedJobs, scheduledAppointments, ongoingJobs, completedToday)
    }

    private fun parseMechanicJobsArray(arr: JSONArray?): List<MechanicJob> {
        val list = mutableListOf<MechanicJob>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                val vehicle = obj.optJSONObject("vehicle")
                list.add(MechanicJob(
                    id = obj.optString("id", ""),
                    customerName = obj.optString("customerName", ""),
                    vehicle = vehicle?.let { "${it.optString("make", "")} ${it.optString("model", "")}" } ?: "",
                    plate = vehicle?.optString("plate", "") ?: "",
                    status = obj.optString("status", ""),
                    priority = obj.optString("priority", "normal"),
                    description = obj.optString("description", ""),
                    concern = obj.optString("issueConcern", ""),
                    mechanicId = obj.optString("mechanicId", null),
                    mechanicName = obj.optString("mechanicName", ""),
                    appointmentId = obj.optString("appointmentId", null),
                    appointmentDate = obj.optString("appointmentDate", ""),
                    hasInvoice = obj.optBoolean("hasInvoice", false),
                    updatedAt = obj.optLong("updatedAtEpochMs", 0)
                ))
            }
        }
        return list
    }

    private fun parseAppointmentsArray(arr: JSONArray?): List<MechanicAppointment> {
        val list = mutableListOf<MechanicAppointment>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(MechanicAppointment(
                    id = obj.optString("id", ""),
                    vehicle = obj.optString("vehicle", ""),
                    plate = obj.optString("plate", ""),
                    appointmentDate = obj.optString("appointmentDate", ""),
                    concern = obj.optString("concern", ""),
                    status = obj.optString("status", "")
                ))
            }
        }
        return list
    }

    fun getJobs(): List<MechanicJob> {
        return try {
            val response = ApiClient.get("mechanic/jobs.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseMechanicJobsArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    fun getJob(jobId: String): MechanicJob? {
        return try {
            val response = ApiClient.get("mechanic/job.php?id=$jobId")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    val item = json.getJSONObject("item")
                    val vehicle = item.optJSONObject("vehicle")
                    MechanicJob(
                        id = item.optString("id", ""),
                        customerName = item.optString("customerName", ""),
                        vehicle = vehicle?.let { "${it.optString("make", "")} ${it.optString("model", "")}" } ?: "",
                        plate = vehicle?.optString("plate", "") ?: "",
                        status = item.optString("status", ""),
                        priority = item.optString("priority", "normal"),
                        description = item.optString("description", ""),
                        concern = item.optString("issueConcern", ""),
                        mechanicId = item.optString("mechanicId", null),
                        mechanicName = item.optString("mechanicName", ""),
                        appointmentId = item.optString("appointmentId", null),
                        appointmentDate = item.optString("appointmentDate", ""),
                        hasInvoice = item.optBoolean("hasInvoice", false),
                        updatedAt = item.optLong("updatedAtEpochMs", 0)
                    )
                } else null
            } else null
        } catch (e: Exception) { null }
    }

    fun updateJobStatus(jobId: String, status: String, progressNote: String? = null): Boolean {
        return try {
            val body = JSONObject().apply {
                put("jobId", jobId)
                put("status", status)
                progressNote?.let { put("progressNote", it) }
            }.toString()

            val response = ApiClient.post("mechanic/job_status.php", body)
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                json.optBoolean("ok", false)
            } else false
        } catch (e: Exception) { false }
    }

    fun getAppointments(): List<MechanicAppointment> {
        return try {
            val response = ApiClient.get("mechanic/appointments.php")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseAppointmentsArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    fun getJobNotes(jobId: String): List<JobNote> {
        return try {
            val response = ApiClient.get("mechanic/job_notes.php?jobId=$jobId")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parseNotesArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    private fun parseNotesArray(arr: JSONArray?): List<JobNote> {
        val list = mutableListOf<JobNote>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(JobNote(
                    id = obj.optString("id", ""),
                    note = obj.optString("note", ""),
                    noteType = obj.optString("noteType", "mechanic"),
                    isCustomerVisible = obj.optBoolean("isCustomerVisible", false),
                    createdAt = obj.optLong("createdAtEpochMs", 0)
                ))
            }
        }
        return list
    }

    fun addJobNote(jobId: String, note: String, noteType: String = "mechanic"): Boolean {
        return try {
            val body = JSONObject().apply {
                put("jobId", jobId)
                put("note", note)
                put("noteType", noteType)
            }.toString()

            val response = ApiClient.post("mechanic/job_notes.php", body)
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                json.optBoolean("ok", false)
            } else false
        } catch (e: Exception) { false }
    }

    fun getJobParts(jobId: String): List<JobPart> {
        return try {
            val response = ApiClient.get("mechanic/job_parts.php?jobId=$jobId")
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                if (json.optBoolean("ok", false)) {
                    parsePartsArray(json.optJSONArray("items"))
                } else emptyList()
            } else emptyList()
        } catch (e: Exception) { emptyList() }
    }

    private fun parsePartsArray(arr: JSONArray?): List<JobPart> {
        val list = mutableListOf<JobPart>()
        arr?.let {
            for (i in 0 until it.length()) {
                val obj = it.getJSONObject(i)
                list.add(JobPart(
                    id = obj.optString("id", ""),
                    inventoryId = obj.optString("inventoryId", ""),
                    partName = obj.optString("partName", ""),
                    quantity = obj.optInt("quantity", 0),
                    unitPrice = obj.optDouble("unitPrice", 0.0),
                    createdAt = obj.optLong("createdAtEpochMs", 0)
                ))
            }
        }
        return list
    }

    fun addJobPart(jobId: String, inventoryId: String, quantity: Int): Boolean {
        return try {
            val body = JSONObject().apply {
                put("jobId", jobId)
                put("inventoryId", inventoryId)
                put("quantity", quantity)
            }.toString()

            val response = ApiClient.post("mechanic/job_parts.php", body)
            if (response.isSuccessful) {
                val json = JSONObject(response.body?.string() ?: "{}")
                json.optBoolean("ok", false)
            } else false
        } catch (e: Exception) { false }
    }
}