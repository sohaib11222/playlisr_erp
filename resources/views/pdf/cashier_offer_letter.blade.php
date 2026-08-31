<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #1a1a1a; }
    .logo { text-align: center; margin-bottom: 26px; }
    .logo img { height: 42px; }
    h2 { font-size: 13px; margin: 18px 0 4px; }
    ul { margin: 4px 0 0; padding-left: 20px; }
    li { margin-bottom: 3px; }
    .sig-line { border-top: 1px solid #999; margin-top: 34px; padding-top: 18px; }
</style>
</head>
<body>
    <div class="logo"><img src="{{ public_path('img/nivessa-logo.png') }}" width="150" height="32"></div>

    <p>Dear {{ $firstName }},</p>

    <p>We are pleased to offer you the position of <strong>{{ $jobTitle }}</strong> at Nivessa Records.</p>

    <h2>Position Details:</h2>
    <ul>
        <li><strong>Start Date</strong>: {{ $startDate }}</li>
        <li><strong>Work Location</strong>: 6434 Hollywood Blvd, Los Angeles, CA 90028 or 5770 W Pico Blvd, Los Angeles, CA 90019</li>
        <li><strong>Salary</strong>: $17.87/hour, paid bi-weekly</li>
    </ul>

    <h2>Benefits:</h2>
    <ul>
        <li>20% employee discount on store merchandise (excluding new reissues).</li>
        <li>18% discount on new reissues.</li>
        <li>Performance bonuses</li>
    </ul>

    <h2>Job Responsibilities:</h2>
    <ul>
        <li>Sell products to customers</li>
        <li>Assist with in-store tasks including cashier duties and maintaining store standards.</li>
        <li>Provide responsive customer support and resolve inquiries promptly.</li>
        <li>Help sort through the inventory at the warehouse</li>
    </ul>

    <h2>Additional Information:</h2>
    <p>This <a href="https://docs.google.com/document/d/1lDA1vToBw-zsPXTYLHNHPJN926_zRI70pSnq1M8Nx4c/edit">Nivessa Handbook</a> outlines all policies and procedures. By accepting this offer, you agree to adhere to the guidelines in the handbook.</p>

    <p>We are excited to officially welcome you to the Nivessa Records team!</p>

    <p>Best,<br>Jon</p>

    <div class="sig-line">
        <p><strong>Acceptance of Offer:</strong></p>
        <p>I, {{ $fullName }}, accept the offer of employment as {{ $jobTitle }} at Nivessa Records.</p>
        <p style="margin-top:28px;">Signature: _____________________</p>
        <p style="margin-top:18px;">Date: _____________</p>
    </div>
</body>
</html>
