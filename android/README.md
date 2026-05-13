# MECHANIX Android App Integration

## Setup

### 1. Add OkHttp dependency
In `build.gradle` (app module):
```groovy
dependencies {
    implementation 'com.squareup.okhttp3:okhttp:4.12.0'
    implementation 'org.json:json:20231013'
}
```

### 2. Initialize TokenManager
In your Application class or MainActivity:
```kotlin
override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    TokenManager.init(this)
}
```

### 3. Login Flow
```kotlin
// Login
val result = AuthService.login(username, password)
if (result.success) {
    when (result.userRole) {
        "customer" -> navigateToCustomerDashboard()
        "mechanic" -> navigateToMechanicDashboard()
        "admin" -> navigateToAdmin()
        "cashier" -> navigateToCashier()
    }
}

// Check login state
if (AuthService.isLoggedIn()) {
    // Show logged in screen
}

// Logout
AuthService.logout()
```

## Customer Features

### Dashboard
```kotlin
val dashboard = CustomerService.getDashboard()
dashboard?.activeJobs?.forEach { job ->
    Log.d("Job", "Vehicle: ${job.vehicle}, Status: ${job.status}")
}
dashboard?.vehicles?.forEach { vehicle ->
    Log.d("Vehicle", "${vehicle.make} ${vehicle.model} - ${vehicle.plate}")
}
```

### Get Vehicles
```kotlin
val vehicles = CustomerService.getVehicles()
```

### Create Appointment
```kotlin
val success = CustomerService.createAppointment(
    vehicleId = "123",
    appointmentDate = "2026-06-15",
    concern = "Engine noise"
)
```

### Cancel Appointment
```kotlin
val success = CustomerService.cancelAppointment(
    appointmentId = "456",
    reason = "Changed my mind"
)
```

### Get Jobs
```kotlin
val jobs = CustomerService.getJobs()
```

### Get Invoices
```kotlin
val invoices = CustomerService.getInvoices()
invoices.forEach { invoice ->
    Log.d("Invoice", "${invoice.invoiceNo} - ${invoice.amount}")
}
```

### Pay Invoice
```kotlin
val success = CustomerService.payInvoice(
    invoiceId = "789",
    amount = 1500.00,
    paymentMethod = "cash",
    referenceNo = "OR-001"
)
```

## Mechanic Features

### Dashboard
```kotlin
val dashboard = MechanicService.getDashboard()
dashboard?.assignedJobs?.forEach { job ->
    Log.d("Job", "${job.vehicle} - ${job.status}")
}
dashboard?.completedToday?.let { count ->
    Log.d("Today", "Completed: $count")
}
```

### Get Jobs
```kotlin
val jobs = MechanicService.getJobs()
```

### Update Job Status
```kotlin
val success = MechanicService.updateJobStatus(
    jobId = "123",
    status = "in_repair",
    progressNote = "Started diagnosis"
)
```

Valid statuses: `pending_inspection`, `in_repair`, `waiting_for_parts`, `completed`

### Get/Add Job Notes
```kotlin
val notes = MechanicService.getJobNotes("123")

val added = MechanicService.addJobNote(
    jobId = "123",
    note = "Replaced brake pads",
    noteType = "mechanic"
)
```

### Get/Add Job Parts
```kotlin
val parts = MechanicService.getJobParts("123")

val added = MechanicService.addJobPart(
    jobId = "123",
    inventoryId = "456",
    quantity = 2
)
```

## API Base URL
Production: `https://ravendark.alwaysdata.net/api/v1/`

## Token Refresh
The ApiClient automatically handles token refresh. If access token expires, it will use the refresh token to get a new access token.

## Data Models
- `CustomerDashboard` - active jobs, vehicles, outstanding balance, recent services
- `Job` - id, vehicle, plate, status, priority, updatedAt
- `Vehicle` - id, make, model, plate, year, color, mileage
- `Appointment` - id, vehicleId, vehicle, plate, appointmentDate, status, concern
- `Invoice` - id, invoiceNo, amount, paidAmount, status, dueDate
- `MechanicDashboard` - assigned jobs, scheduled appointments, ongoing jobs, completed today
- `MechanicJob` - extended job info with customer name, description, concern
- `JobNote` - id, note, noteType, isCustomerVisible, createdAt
- `JobPart` - id, inventoryId, partName, quantity, unitPrice, createdAt