<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Receipt - {{ $orgName }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; color: #111; max-width: 800px; margin: 0 auto; }
        .receipt { border: 1px solid #ddd; border-radius: 8px; padding: 24px; }
        .header { text-align: center; border-bottom: 2px solid #eee; padding-bottom: 16px; margin-bottom: 16px; }
        .header h1 { font-size: 20px; margin-bottom: 4px; }
        .header p { font-size: 12px; color: #666; }
        .section { margin-bottom: 16px; }
        .section-title { font-size: 14px; font-weight: bold; margin-bottom: 8px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 4px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; border-bottom: 1px solid #f5f5f5; }
        .row:last-child { border-bottom: none; }
        .label { color: #666; }
        .value { font-weight: 500; text-align: right; }
        .amount { font-size: 18px; font-weight: bold; color: #2563eb; }
        .signatories { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 12px; }
        .signatory { border: 1px solid #ddd; border-radius: 6px; padding: 12px; text-align: center; }
        .signatory-role { font-size: 12px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 8px; }
        .signatory-name { font-size: 13px; font-weight: 500; margin-bottom: 6px; }
        .signatory-status { font-size: 11px; margin-bottom: 6px; }
        .signatory-status.approved { color: #16a34a; }
        .signatory-status.rejected { color: #dc2626; }
        .signatory-status.pending { color: #ca8a04; }
        .signature-img { max-height: 50px; max-width: 100%; object-fit: contain; margin: 4px auto; display: block; }
        .signature-date { font-size: 10px; color: #888; margin-top: 4px; }
        .footer { margin-top: 20px; padding-top: 12px; border-top: 2px solid #eee; text-align: center; font-size: 11px; color: #888; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-pending { background: #fef9c3; color: #854d0e; }
        .no-print { text-align: center; margin-top: 16px; }
        .no-print button { padding: 8px 16px; margin: 0 4px; border: 1px solid #ddd; background: #fff; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .no-print button:hover { background: #f5f5f5; }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <h1>{{ $orgName }}</h1>
            <p>Financial Withdrawal Receipt</p>
            <p style="margin-top: 8px; font-size: 11px;">Generated: {{ $generatedAt }}</p>
        </div>

        <div class="section">
            <div class="section-title">Transaction Details</div>
            <div class="row">
                <span class="label">Receipt Number</span>
                <span class="value">{{ substr($withdrawal->id, 0, 8) }}</span>
            </div>
            <div class="row">
                <span class="label">Wallet Type</span>
                <span class="value">{{ ucfirst($walletType) }}</span>
            </div>
            <div class="row">
                <span class="label">Amount</span>
                <span class="value amount">KES {{ number_format((float) $withdrawal->amount, 2) }}</span>
            </div>
            <div class="row">
                <span class="label">Reason / Narration</span>
                <span class="value">{{ $withdrawal->reason }}</span>
            </div>
            @if($withdrawal->phone_number)
            <div class="row">
                <span class="label">Recipient Phone</span>
                <span class="value">{{ $withdrawal->phone_number }}</span>
            </div>
            @endif
            <div class="row">
                <span class="label">Date</span>
                <span class="value">{{ $withdrawal->submitted_at ?? $withdrawal->created_at }}</span>
            </div>
            <div class="row">
                <span class="label">Status</span>
                <span class="value">{{ ucfirst($withdrawal->status) }}</span>
            </div>
            @if($messageReference)
            <div class="row">
                <span class="label">B2C Reference</span>
                <span class="value">{{ $messageReference }}</span>
            </div>
            @endif
        </div>

        <div class="section">
            <div class="section-title">Signatories</div>
            <div class="signatories">
                @foreach($signatories as $sig)
                    @php
                        $status = $sig->status ?? 'pending';
                        $badgeClass = $status === 'approved' ? 'badge-approved' : ($status === 'rejected' ? 'badge-rejected' : 'badge-pending');
                    @endphp
                    <div class="signatory">
                        <div class="signatory-role">{{ ucfirst($sig->signatory_role) }}</div>
                        <div class="signatory-name">{{ $sig->full_name ?? ($sig->signatory_user_id ? 'Approved' : 'Pending') }}</div>
                        <div class="signatory-status {{ $badgeClass }}">
                            <span class="badge {{ $badgeClass }}">{{ ucfirst($status) }}</span>
                        </div>
                        @if($sig->signature_url)
                            <img src="{{ $sig->signature_url }}" alt="{{ $sig->signatory_role }} signature" class="signature-img" />
                        @endif
                        @if($sig->approved_at)
                            <div class="signature-date">{{ $sig->approved_at }}</div>
                        @elseif($sig->rejected_at)
                            <div class="signature-date">{{ $sig->rejected_at }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="footer">
            <p>This is an official receipt from {{ $orgName }}.</p>
            <p>Verify this receipt through the official channels.</p>
        </div>

        <div class="no-print">
            <button onclick="window.print()">Print Receipt</button>
            <button onclick="window.close()">Close</button>
        </div>
    </div>
</body>
</html>
