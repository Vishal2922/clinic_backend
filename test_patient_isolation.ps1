$base = 'http://localhost/clinic_backend/public'
$results = @()
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
        return @{id=$id; name=$name; status=$r.StatusCode; result='PASS'; detail=$s; content=$c}
    } catch {
        $code = 0; $msg = ''
        try { 
            $code = [int]$_.Exception.Response.StatusCode
            $stream = $_.Exception.Response.GetResponseStream()
            $rd = New-Object System.IO.StreamReader($stream)
            $msg = $rd.ReadToEnd() 
        } catch { $msg = $_.Exception.Message }
        if (-not $msg) { $msg = $_.Exception.Message }
        $s = if ($msg.Length -gt 90) { $msg.Substring(0,90)+'...' } else { $msg }
        return @{id=$id; name=$name; status=$code; result='FAIL'; detail=$s; content=$msg}
    }
}

Write-Output ''
Write-Output '======= ISOLATION TEST (Vishal Patient) ======='
Write-Output ''

# Assume 'apollo' tenant was seeded with 'apollo_vishal_patient'
$tid = 'apollo'
$tH = @{'X-Tenant-ID'=$tid}

# 1. Login as Vishal Patient
$loginBody = '{"username":"apollo_vishal_patient","password":"Apollo@1234"}'
try {
    $loginR = Invoke-WebRequest -Uri "$base/api/auth/login" -Method POST -ContentType 'application/json' -Headers $tH -Body $loginBody -UseBasicParsing -WebSession $session
    $tData = $loginR.Content | ConvertFrom-Json
    $patToken = $tData.data.access_token
    $results += @{id='I01'; name='Patient Login'; status=200; result='PASS'; detail='OK'}
} catch { 
    Write-Output "Login Failed: $($_.ErrorDetails.Message)"
    exit 1 
}

$authH = @{'X-Tenant-ID'=$tid; Authorization="Bearer $patToken"}

# Get CSRF
try {
    $csrfR = Invoke-WebRequest -Uri "$base/api/auth/csrf-token" -Method GET -Headers $authH -UseBasicParsing -WebSession $session
    $csrfData = $csrfR.Content | ConvertFrom-Json
    $csrf = $csrfData.data.csrf_token
} catch { $csrf = '' }

$authHC = @{'X-Tenant-ID'=$tid; Authorization="Bearer $patToken"; 'X-CSRF-TOKEN'=$csrf}

# 2. Check Context
$meTest = Test-EP 'I02' 'Auth Me Context' 'GET' "$base/api/auth/me" $null $authH $session
$results += $meTest

# Extracted IDs for tests
$patId = ($meTest.content | ConvertFrom-Json).data.patient_id
Write-Output "Detected Patient ID: $patId"

# ================================
# APPOINTMENTS
# ================================
# Should only list own
$results += Test-EP 'I03' 'List Appointments' 'GET' "$base/api/appointments" $null $authH $session

# Try to book
$fd = (Get-Date).AddDays(2).ToString('yyyy-MM-dd HH:mm:ss')
$ab = '{"patient_id":999,"doctor_id":1,"appointment_time":"' + $fd + '","reason":"Checkup"}'
# IDOR check: backend should ignore '999' and use Patient's own ID
$bkTest = Test-EP 'I04' 'Book Appointment' 'POST' "$base/api/appointments/book" $ab $authHC $session
$results += $bkTest
$appId = ($bkTest.content | ConvertFrom-Json).data.id

# IDOR: Try to cancel someone else's appointment (assuming ID 2 belongs to another or doesn't exist, we just want a 403 or 404, not success)
$results += Test-EP 'I05' 'Cancel Other Appt' 'DELETE' "$base/api/appointments/2/cancel" $null $authH $session

# Cancel own appointment
if ($appId) {
    $results += Test-EP 'I06' 'Cancel Own Appt' 'DELETE' "$base/api/appointments/$appId/cancel" $null $authH $session
}

# ================================
# BILLING
# ================================
$results += Test-EP 'I07' 'Billing Summary' 'GET' "$base/api/billing/summary" $null $authH $session
$results += Test-EP 'I08' 'List Invoices' 'GET' "$base/api/billing/invoices" $null $authH $session

# Show own invoice (assume ID 1 belongs to user from seeders)
$results += Test-EP 'I09' 'Show Own Invoice' 'GET' "$base/api/billing/invoices/1" $null $authH $session

# Mark as paid (Should fail - no payment method)
$results += Test-EP 'I10' 'Mark Paid (No Method)' 'PATCH' "$base/api/billing/invoices/1/status" '{"status":"paid"}' $authHC $session

# Mark as paid (Should pass)
$results += Test-EP 'I11' 'Mark Paid (With Method)' 'PATCH' "$base/api/billing/invoices/1/status" '{"status":"paid","payment_method":"Credit Card"}' $authHC $session

# Mark as paid again (Should fail - already paid)
$results += Test-EP 'I12' 'Mark Already Paid' 'PATCH' "$base/api/billing/invoices/1/status" '{"status":"cancelled"}' $authHC $session

# ================================
# PRESCRIPTIONS
# ================================
$results += Test-EP 'I13' 'List Prescriptions' 'GET' "$base/api/prescriptions" $null $authH $session
# Show own prescription (assume ID 1 belongs to user)
$results += Test-EP 'I14' 'Show Own Presc' 'GET' "$base/api/prescriptions/1" $null $authH $session
$results += Test-EP 'I15' 'Download Presc' 'GET' "$base/api/prescriptions/1/download" $null $authH $session

# Attempt Create (Should 403 - Provider only)
$results += Test-EP 'I16' 'Attempt Create Presc' 'POST' "$base/api/prescriptions" '{"medicine_name":"Test"}' $authHC $session

# REPORT
Write-Output 'ID   | Test Name              | HTTP | Result | Details'
Write-Output '_____|________________________|______|________|________'
$pass = 0; $fail = 0
foreach ($r in $results) {
    $d = if ($r.detail.Length -gt 65) { $r.detail.Substring(0,65)+'...' } else { $r.detail }
    
    # We expect some failures (403/422/404) for negative tests to be "Passes" in terms of security
    $expectedFailures = @('I05','I10','I12','I16')
    $actualResult = $r.result
    
    if ($r.id -in $expectedFailures) {
        if ($r.status -in @(403, 422, 404)) {
            $actualResult = 'PASS(Sec)'
        } else {
            $actualResult = 'FAIL(Sec)'
        }
    }

    $line = '{0,-4} | {1,-22} | {2,-4} | {3,-9} | {4}' -f $r.id, $r.name, $r.status, $actualResult, $d
    Write-Output $line
    if ($actualResult -like 'PASS*') { $pass++ } else { $fail++ }
}
Write-Output ''
Write-Output "======= TOTAL: $($results.Count) | PASS: $pass | FAIL: $fail ======="
