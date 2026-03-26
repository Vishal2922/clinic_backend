$base = 'http://localhost/clinic_backend/public'
$results = @()
# Cookie jar for session persistence (CSRF needs PHPSESSID)
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

function Test-EP {
    param($id, $name, $method, $url, $body=$null, $hdrs=@{}, $sess=$null)
    try {
        $params = @{Uri=$url; Method=$method; UseBasicParsing=$true; Headers=$hdrs}
        if ($body) { $params.Body = $body; $params.ContentType = 'application/json' }
        if ($sess) { $params.WebSession = $sess }
        $r = Invoke-WebRequest @params
        $c = $r.Content
        $s = if ($c.Length -gt 90) { $c.Substring(0,90)+'...' } else { $c }
        return @{id=$id; name=$name; status=$r.StatusCode; result='PASS'; detail=$s}
    } catch {
        $code = 0; $msg = ''
        try { $code = [int]$_.Exception.Response.StatusCode; $stream = $_.Exception.Response.GetResponseStream(); $rd = New-Object System.IO.StreamReader($stream); $msg = $rd.ReadToEnd() } catch { $msg = $_.Exception.Message }
        if (-not $msg) { $msg = $_.Exception.Message }
        $s = if ($msg.Length -gt 90) { $msg.Substring(0,90)+'...' } else { $msg }
        return @{id=$id; name=$name; status=$code; result='FAIL'; detail=$s}
    }
}

Write-Output ''
Write-Output '======= COMPLETE API TEST (SESSION-AWARE) ======='
Write-Output ''

# ═══ SA LOGIN ═══
$saR = Invoke-RestMethod -Uri "$base/api/super-admin/auth/login" -Method POST -ContentType 'application/json' -Body '{"email":"superadmin@clinic.io","password":"SuperAdmin@2026"}'
$saToken = $saR.data.access_token
$saH = @{Authorization="Bearer $saToken"}
$results += @{id='T01'; name='SA Login'; status=200; result='PASS'; detail='OK'}

# ═══ CREATE FRESH TENANT ═══
$ts = Get-Date -Format 'HHmmss'
$tc = "st$ts"
$tb = '{"tenant_code":"' + $tc + '","name":"Session Test","email":"st@t.com","phone":"+91 111","city":"D","state":"D","country":"IN","plan":"standard","admin_email":"a@st.com","admin_name":"ST Admin"}'
try {
    $ntR = Invoke-RestMethod -Uri "$base/api/super-admin/tenants" -Method POST -ContentType 'application/json' -Headers $saH -Body $tb
    $tid = $tc
    $adminPass = $ntR.data.admin_credentials.password
    $adminUser = ($tc -replace '[^a-z0-9]','') + '_admin'
    $results += @{id='T02'; name='SA Create Tenant'; status=201; result='PASS'; detail="$tid/$adminPass"}
} catch { Write-Output "CRITICAL: $($_.ErrorDetails.Message)"; exit 1 }

# ═══ SA MODULE ═══
$results += Test-EP 'T03' 'SA Me' 'GET' "$base/api/super-admin/auth/me" $null $saH
$results += Test-EP 'T04' 'SA Dashboard' 'GET' "$base/api/super-admin/dashboard" $null $saH
$results += Test-EP 'T05' 'SA Tenants List' 'GET' "$base/api/super-admin/tenants" $null $saH
$results += Test-EP 'T06' 'SA Audit Log' 'GET' "$base/api/super-admin/audit-log" $null $saH
$results += Test-EP 'T07' 'SA Show Tenant' 'GET' "$base/api/super-admin/tenants/$tid" $null $saH
$results += Test-EP 'T08' 'SA Tenant Stats' 'GET' "$base/api/super-admin/tenants/$tid/stats" $null $saH
$results += Test-EP 'T09' 'SA Update Tenant' 'PUT' "$base/api/super-admin/tenants/$tid" '{"name":"Updated"}' $saH
$results += Test-EP 'T10' 'SA Suspend' 'POST' "$base/api/super-admin/tenants/$tid/suspend" '{"reason":"test"}' $saH
$results += Test-EP 'T11' 'SA Reactivate' 'POST' "$base/api/super-admin/tenants/$tid/reactivate" $null $saH
$results += Test-EP 'T12' 'SA Change Plan' 'POST' "$base/api/super-admin/tenants/$tid/change-plan" '{"plan":"professional"}' $saH

# ═══ TENANT LOGIN (with session) ═══
$tH = @{'X-Tenant-ID'=$tid}
$loginBody = '{"username":"' + $adminUser + '","password":"' + $adminPass + '"}'
try {
    $loginR = Invoke-WebRequest -Uri "$base/api/auth/login" -Method POST -ContentType 'application/json' -Headers $tH -Body $loginBody -UseBasicParsing -WebSession $session
    $tData = $loginR.Content | ConvertFrom-Json
    $tenantToken = $tData.data.access_token
    $results += @{id='T13'; name='Tenant Login'; status=200; result='PASS'; detail='OK'}
} catch { $results += @{id='T13'; name='Tenant Login'; status=0; result='FAIL'; detail=$_.ErrorDetails.Message}; $tenantToken='' }

$authH = @{'X-Tenant-ID'=$tid; Authorization="Bearer $tenantToken"}

# ═══ GET CSRF (same session!) ═══
try {
    $csrfR = Invoke-WebRequest -Uri "$base/api/auth/csrf-token" -Method GET -Headers $authH -UseBasicParsing -WebSession $session
    $csrfData = $csrfR.Content | ConvertFrom-Json
    $csrf = $csrfData.data.csrf_token
    $results += @{id='T14'; name='CSRF Token'; status=200; result='PASS'; detail="csrf=$($csrf.Substring(0,16))..."}
} catch { $csrf = ''; $results += @{id='T14'; name='CSRF Token'; status=0; result='FAIL'; detail=$_.ErrorDetails.Message} }

$authHC = @{'X-Tenant-ID'=$tid; Authorization="Bearer $tenantToken"; 'X-CSRF-TOKEN'=$csrf}

# ═══ AUTH ═══
$results += Test-EP 'T15' 'Auth Me' 'GET' "$base/api/auth/me" $null $authH $session
$results += Test-EP 'T16' 'Register Provider' 'POST' "$base/api/auth/register" '{"username":"dr_s","password":"Doctor@123","email":"dr@s.com","full_name":"Dr S","phone":"2222","role_id":2}' $tH $session
$results += Test-EP 'T17' 'Register Nurse' 'POST' "$base/api/auth/register" '{"username":"nurse_s","password":"Nurse@123","email":"n@s.com","full_name":"Nurse S","phone":"3333","role_id":3}' $tH $session

# ═══ ROLES ═══
$results += Test-EP 'T18' 'List Roles' 'GET' "$base/api/users/roles" $null $authH $session

# ═══ PATIENTS (CRUD) ═══
$results += Test-EP 'T19' 'List Patients' 'GET' "$base/api/patients" $null $authH $session
$results += Test-EP 'T20' 'Create Patient' 'POST' "$base/api/patients" '{"name":"Jane Doe","phone":"5559876","medical_history":"None"}' $authHC $session
$results += Test-EP 'T21' 'Get Patient 1' 'GET' "$base/api/patients/1" $null $authH $session
$results += Test-EP 'T22' 'Update Patient 1' 'PUT' "$base/api/patients/1" '{"name":"Jane Updated","phone":"5550000"}' $authHC $session
$results += Test-EP 'T23' 'Delete Patient 1' 'DELETE' "$base/api/patients/1" $null $authH $session

# ═══ APPOINTMENTS (CRUD) ═══
$results += Test-EP 'T24' 'List Appointments' 'GET' "$base/api/appointments" $null $authH $session
$fd = (Get-Date).AddDays(7).ToString('yyyy-MM-dd HH:mm:ss')
$ab = '{"patient_id":1,"doctor_id":1,"appointment_time":"' + $fd + '","reason":"Checkup"}'
$results += Test-EP 'T25' 'Book Appointment' 'POST' "$base/api/appointments/book" $ab $authHC $session
$results += Test-EP 'T26' 'Update Appt Status' 'PATCH' "$base/api/appointments/1/status" '{"status":"arrived"}' $authHC $session
$results += Test-EP 'T27' 'Cancel Appointment' 'DELETE' "$base/api/appointments/1/cancel" $null $authH $session

# ═══ PRESCRIPTIONS (Provider) ═══
# New session for Provider
$drSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$drLoginBody = '{"username":"dr_s","password":"Doctor@123"}'
try {
    $drLR = Invoke-WebRequest -Uri "$base/api/auth/login" -Method POST -ContentType 'application/json' -Headers $tH -Body $drLoginBody -UseBasicParsing -WebSession $drSession
    $drData = $drLR.Content | ConvertFrom-Json
    $drToken = $drData.data.access_token
    $drH = @{'X-Tenant-ID'=$tid; Authorization="Bearer $drToken"}
    # Get CSRF in same session
    $drCsrfR = Invoke-WebRequest -Uri "$base/api/auth/csrf-token" -Method GET -Headers $drH -UseBasicParsing -WebSession $drSession
    $drCsrfData = $drCsrfR.Content | ConvertFrom-Json
    $drCSRF = $drCsrfData.data.csrf_token
    $drHC = @{'X-Tenant-ID'=$tid; Authorization="Bearer $drToken"; 'X-CSRF-TOKEN'=$drCSRF}
    $results += @{id='T28'; name='Provider Login+CSRF'; status=200; result='PASS'; detail='OK'}
} catch {
    $results += @{id='T28'; name='Provider Login+CSRF'; status=0; result='FAIL'; detail=$_.ErrorDetails.Message}
    $drHC = $authHC; $drH = $authH; $drSession = $session
}

$results += Test-EP 'T29' 'Create Prescription' 'POST' "$base/api/prescriptions" '{"patient_id":1,"medicine_name":"Amoxicillin","dosage":"1 tab 3x","duration_days":7}' $drHC $drSession
$results += Test-EP 'T30' 'Update Prescription' 'PUT' "$base/api/prescriptions/1" '{"status":"dispensed"}' $drHC $drSession

# ═══ DASHBOARD ═══
$results += Test-EP 'T31' 'Dashboard Stats' 'GET' "$base/api/dashboard/stats" $null $authH $session

# ═══ COMMUNICATION ═══
$results += Test-EP 'T32' 'Get Notes' 'GET' "$base/api/communication/appointments/1/notes" $null $drH $drSession
$results += Test-EP 'T33' 'Create Note' 'POST' "$base/api/communication/appointments/1/notes" '{"message":"Stable","note_type":"note","visible_to_role":"all"}' $drHC $drSession
$results += Test-EP 'T34' 'Note History' 'GET' "$base/api/communication/appointments/1/notes/history" $null $drH $drSession

# ═══ BILLING ═══
$results += Test-EP 'T35' 'Billing Summary' 'GET' "$base/api/billing/summary" $null $authH $session
$results += Test-EP 'T36' 'List Invoices' 'GET' "$base/api/billing/invoices" $null $authH $session
$results += Test-EP 'T37' 'Create Invoice' 'POST' "$base/api/billing/invoices" '{"patient_id":1,"amount":500,"tax_percent":18}' $authHC $session

# ═══ STAFF ═══
$results += Test-EP 'T38' 'List Staff' 'GET' "$base/api/staff" $null $authH $session
$results += Test-EP 'T39' 'Staff Departments' 'GET' "$base/api/staff/departments" $null $authH $session
$results += Test-EP 'T40' 'Create Staff' 'POST' "$base/api/staff" '{"user_id":2,"department":"Cardiology"}' $authHC $session
$results += Test-EP 'T41' 'Get Staff 1' 'GET' "$base/api/staff/1" $null $authH $session
$results += Test-EP 'T42' 'Update Staff 1' 'PUT' "$base/api/staff/1" '{"department":"Neuro"}' $authHC $session
$results += Test-EP 'T43' 'Delete Staff 1' 'DELETE' "$base/api/staff/1" $null $authH $session

# ═══ CALENDAR ═══
$td = Get-Date -Format 'yyyy-MM-dd'
$results += Test-EP 'T44' 'Calendar Events' 'GET' "$base/api/calendar/events?date=$td" $null $authH $session
$calRangeUrl = $base + '/api/calendar/range?start_date=2026-02-01' + '&' + 'end_date=2026-03-31'
$results += Test-EP 'T45' 'Calendar Range' 'GET' $calRangeUrl $null $authH $session
$calMonthUrl = $base + '/api/calendar/monthly?year=2026' + '&' + 'month=2'
$results += Test-EP 'T46' 'Monthly Summary' 'GET' $calMonthUrl $null $authH $session
$calDoctorUrl = $base + '/api/calendar/doctor/1?start_date=2026-02-01' + '&' + 'end_date=2026-03-31'
$results += Test-EP 'T47' 'Doctor Schedule' 'GET' $calDoctorUrl $null $authH $session

# ═══ SETTINGS ═══
$results += Test-EP 'T48' 'List Sessions' 'GET' "$base/api/settings/sessions" $null $authH $session
$results += Test-EP 'T49' 'Audit Log' 'GET' "$base/api/settings/audit-log" $null $authH $session
$results += Test-EP 'T50' 'Audit Actions' 'GET' "$base/api/settings/audit-log/actions" $null $authH $session
$cpBody = '{"current_password":"' + $adminPass + '","new_password":"NewPass@456","new_password_confirmation":"NewPass@456"}'
$results += Test-EP 'T51' 'Change Password' 'POST' "$base/api/settings/change-password" $cpBody $authHC $session
$results += Test-EP 'T52' 'CSRF Regenerate' 'GET' "$base/api/settings/csrf-token" $null $authH $session

# REPORT
Write-Output 'ID   | Test Name              | HTTP | Result | Details'
Write-Output '_____|________________________|______|________|________'
$pass = 0; $fail = 0
foreach ($r in $results) {
    $d = if ($r.detail.Length -gt 65) { $r.detail.Substring(0,65)+'...' } else { $r.detail }
    $line = '{0,-4} | {1,-22} | {2,-4} | {3,-6} | {4}' -f $r.id, $r.name, $r.status, $r.result, $d
    Write-Output $line
    if ($r.result -eq 'PASS') { $pass++ } else { $fail++ }
}
Write-Output ''
Write-Output "======= TOTAL: $($results.Count) | PASS: $pass | FAIL: $fail ======="
