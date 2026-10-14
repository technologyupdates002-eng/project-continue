import { useState, useCallback, useRef } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as XLSX from 'xlsx';
import {
  Upload, FileSpreadsheet, AlertTriangle, CheckCircle, XCircle,
  Loader2, ArrowRight, Phone, User, Calendar, Hash, Download,
} from 'lucide-react';
import { Card, Button, Badge } from '@/components/ui';
import { formatCurrency, formatDate, classNames, downloadCSV } from '@/lib/format';
import { importsApi, type BankStatementRow, type ImportBatchResponse } from '@/api/imports';
import { queryKeys } from '@/lib/query';

interface ParsedTransaction {
  date: string;
  rawDate: string;
  name: string;
  phone: string;
  reference: string;
  amount: number;
  rawDetails: string;
  mpesaCode: string;
}

interface ParseResult {
  rows: ParsedTransaction[];
  totalAmount: number;
  duplicatesRemoved: number;
  sheetName: string;
  headerRow: number;
  rawRowCount: number;
  skippedRows: number;
}

// ---- Column header synonyms ----
const HEADER_SYNONYMS = {
  date: ['date', 'trans date', 'transaction date', 'value date', 'post date', 'posting date', 'trx date'],
  narration: ['narration', 'details', 'description', 'transaction details', 'particulars', 'remarks', 'memo', 'transaction'],
  reference: ['reference', 'ref', 'reference no', 'reference number', 'ref no', 'transaction id', 'trx id'],
  credit: ['credit', 'deposit', 'cr', 'amount in', 'incoming', 'receipt'],
  debit: ['debit', 'withdrawal', 'dr', 'amount out', 'outgoing', 'payment'],
  amount: ['amount', 'value', 'transaction amount', 'amt'],
  name: ['name', 'account name', 'payer', 'depositor', 'customer name', 'sender'],
  phone: ['phone', 'mobile', 'msisdn', 'tel', 'telephone', 'cell'],
};

const PHONE_REGEX = /(?:\+?254|0)?7\d{8}|(?:\+?254|0)?1\d{8}/g;
const MPESA_REGEX = /[A-Z0-9]{8,12}\s*(?:Confirmed|completed|received)/i;

function normalizeHeader(h: string): string {
  return h.toString().toLowerCase().trim().replace(/[_\-\.]/g, ' ').replace(/\s+/g, ' ');
}

function findHeaderRow(rows: any[][]): number {
  for (let i = 0; i < Math.min(rows.length, 30); i++) {
    const row = rows[i];
    if (!row) continue;
    const cells = row.map((c) => normalizeHeader(String(c || '')));
    const hasDate = cells.some((c) => HEADER_SYNONYMS.date.includes(c));
    const hasNarrationOrAmount = cells.some((c) =>
      HEADER_SYNONYMS.narration.includes(c) ||
      HEADER_SYNONYMS.amount.includes(c) ||
      HEADER_SYNONYMS.credit.includes(c),
    );
    if (hasDate && hasNarrationOrAmount) return i;
  }
  return -1;
}

function findColumnIndex(headerRow: string[], synonyms: string[]): number {
  for (let i = 0; i < headerRow.length; i++) {
    const h = normalizeHeader(headerRow[i] || '');
    if (synonyms.includes(h)) return i;
  }
  return -1;
}

function extractPhone(text: string): string | null {
  const matches = text.match(PHONE_REGEX);
  if (!matches || matches.length === 0) return null;
  let phone = matches[0].replace(/\s|-/g, '');
  if (phone.startsWith('0')) {
    phone = '+254' + phone.slice(1);
  } else if (phone.startsWith('254')) {
    phone = '+' + phone;
  } else if (!phone.startsWith('+254') && phone.length === 9) {
    phone = '+254' + phone;
  }
  return phone;
}

function extractName(text: string): string {
  const cleaned = text
    .replace(/M-Pesa|Paybill|Till|Sent|Received|Confirmed|completed|from|to|Account|Payment/i, ' ')
    .replace(/\+?\d{10,}/g, ' ')
    .replace(/[A-Z0-9]{8,12}/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  const parts = cleaned.split(' ').filter((p) => p.length > 2);
  if (parts.length >= 2) {
    return parts.slice(0, 4).join(' ');
  }
  return parts[0] || '';
}

function extractMpesaCode(text: string): string {
  const match = text.match(MPESA_REGEX);
  if (match) {
    const code = match[0].match(/[A-Z0-9]{8,12}/);
    if (code) return code[0];
  }
  const direct = text.match(/\b([A-Z]{1,4}\d{6,10})\b/);
  return direct ? direct[1] : '';
}

function parseDate(val: any): string | null {
  if (!val && val !== 0) return null;
  if (val instanceof Date) {
    return isNaN(val.getTime()) ? null : val.toISOString().split('T')[0];
  }
  if (typeof val === 'number') {
    const d = XLSX.SSF.parse(val);
    if (d && !isNaN(d.getTime())) return d.toISOString().split('T')[0];
  }
  const str = String(val).trim();
  const d = new Date(str);
  if (!isNaN(d.getTime())) return d.toISOString().split('T')[0];
  const parts = str.match(/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/);
  if (parts) {
    let [, dd, mm, yy] = parts;
    if (yy.length === 2) yy = '20' + yy;
    const date = new Date(parseInt(yy), parseInt(mm) - 1, parseInt(dd));
    if (!isNaN(date.getTime())) return date.toISOString().split('T')[0];
  }
  return null;
}

function parseAmount(val: any): number {
  if (typeof val === 'number') return val;
  if (!val) return 0;
  const str = String(val).replace(/[,\sKESkesh]/gi, '').replace(/[()]/g, '-');
  const n = parseFloat(str);
  return isNaN(n) ? 0 : n;
}

function isBalanceOrTotalRow(row: any[]): boolean {
  const text = row.join(' ').toLowerCase();
  return /(opening balance|closing balance|balance brought forward|balance carried forward|total|subtotal|running balance)/.test(text);
}

function parseWorkbook(file: File): Promise<ParseResult> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      try {
        const data = new Uint8Array(e.target?.result as ArrayBuffer);
        const workbook = XLSX.read(data, { type: 'array', cellDates: true });
        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        const allRows = XLSX.utils.sheet_to_json<any[]>(sheet, { header: 1, raw: true, defval: null }) as any[][];

        const headerRowIdx = findHeaderRow(allRows);
        if (headerRowIdx === -1) {
          reject(new Error('Could not find a header row. Ensure the file has Date, Narration/Details, and Amount/Credit columns.'));
          return;
        }

        const header = allRows[headerRowIdx].map((c) => String(c || ''));
        const dataRows = allRows.slice(headerRowIdx + 1);

        const dateIdx = findColumnIndex(header, HEADER_SYNONYMS.date);
        const narrationIdx = findColumnIndex(header, HEADER_SYNONYMS.narration);
        const referenceIdx = findColumnIndex(header, HEADER_SYNONYMS.reference);
        const creditIdx = findColumnIndex(header, HEADER_SYNONYMS.credit);
        const debitIdx = findColumnIndex(header, HEADER_SYNONYMS.debit);
        const amountIdx = findColumnIndex(header, HEADER_SYNONYMS.amount);
        const nameIdx = findColumnIndex(header, HEADER_SYNONYMS.name);
        const phoneIdx = findColumnIndex(header, HEADER_SYNONYMS.phone);

        if (dateIdx === -1) {
          reject(new Error('Could not find a Date column in the file.'));
          return;
        }

        // Determine credit column: prefer credit/deposit, fallback to amount
        let chosenCreditIdx = creditIdx;
        if (chosenCreditIdx === -1) chosenCreditIdx = amountIdx;
        if (chosenCreditIdx === -1) {
          reject(new Error('Could not find a Credit/Deposit or Amount column.'));
          return;
        }

        // Co-op accommodation: sample rows to check if debit > credit totals
        if (creditIdx !== -1 && debitIdx !== -1) {
          let creditTotal = 0;
          let debitTotal = 0;
          const sampleCount = Math.min(dataRows.length, 19);
          for (let i = 0; i < sampleCount; i++) {
            const row = dataRows[i];
            if (!row || isBalanceOrTotalRow(row)) continue;
            creditTotal += Math.abs(parseAmount(row[creditIdx]));
            debitTotal += Math.abs(parseAmount(row[debitIdx]));
          }
          if (debitTotal > creditTotal && debitTotal > 0) {
            // Swap credit and debit interpretations
            const tmp = chosenCreditIdx;
            chosenCreditIdx = debitIdx;
            // Use the old credit column as debit for filtering
            // (not needed beyond this point since we skip debits)
            void tmp;
          }
        }

        const transactions: ParsedTransaction[] = [];
        let skippedRows = 0;
        let currentNarration = '';

        for (const row of dataRows) {
          if (!row || row.every((c) => c === null || c === undefined || c === '')) continue;
          if (isBalanceOrTotalRow(row)) continue;

          const dateVal = row[dateIdx];
          const parsedDate = parseDate(dateVal);

          // Narration: use column or accumulate continuation
          let narration = '';
          if (narrationIdx !== -1) {
            narration = String(row[narrationIdx] || '').trim();
          }

          // If no date on this row but we have narration, treat as continuation
          if (!parsedDate && narration) {
            currentNarration += ' ' + narration;
            continue;
          }

          if (!parsedDate) {
            skippedRows++;
            continue;
          }

          // Combine any accumulated continuation text
          if (currentNarration) {
            narration = (narration + ' ' + currentNarration).trim();
            currentNarration = '';
          }

          const credit = parseAmount(chosenCreditIdx !== -1 ? row[chosenCreditIdx] : 0);
          const debit = debitIdx !== -1 ? parseAmount(row[debitIdx]) : 0;

          // Skip if debit is present and credit is not
          if (debit > 0 && credit <= 0) {
            skippedRows++;
            continue;
          }

          // Must have positive credit
          if (credit <= 0) {
            skippedRows++;
            continue;
          }

          // Build search text for phone/name extraction
          const refText = referenceIdx !== -1 ? String(row[referenceIdx] || '') : '';
          const nameText = nameIdx !== -1 ? String(row[nameIdx] || '') : '';
          const phoneText = phoneIdx !== -1 ? String(row[phoneIdx] || '') : '';
          const searchText = `${narration} ${refText} ${nameText} ${phoneText}`;

          const phone = phoneText ? extractPhone(phoneText) : extractPhone(searchText);
          if (!phone) {
            skippedRows++;
            continue;
          }

          const name = nameText ? nameText.trim() : extractName(narration + ' ' + refText);
          const mpesaCode = extractMpesaCode(narration + ' ' + refText);

          let reference = refText.trim();
          if (!reference && mpesaCode) reference = mpesaCode;
          if (!reference) reference = `${phone}-${parsedDate}-${credit}`;

          transactions.push({
            date: parsedDate,
            rawDate: String(dateVal || ''),
            name: name || phone,
            phone,
            reference,
            amount: credit,
            rawDetails: narration || refText,
            mpesaCode,
          });
        }

        // Remove in-file duplicates by phone|reference|date|amount
        const seen = new Set<string>();
        const unique: ParsedTransaction[] = [];
        let duplicatesRemoved = 0;

        for (const tx of transactions) {
          const key = `${tx.phone}|${tx.reference}|${tx.date}|${tx.amount}`;
          if (seen.has(key)) {
            duplicatesRemoved++;
            continue;
          }
          seen.add(key);
          unique.push(tx);
        }

        const totalAmount = unique.reduce((sum, t) => sum + t.amount, 0);

        resolve({
          rows: unique,
          totalAmount,
          duplicatesRemoved,
          sheetName: workbook.SheetNames[0],
          headerRow: headerRowIdx + 1,
          rawRowCount: dataRows.length,
          skippedRows,
        });
      } catch (err) {
        reject(err instanceof Error ? err : new Error('Failed to parse file'));
      }
    };
    reader.onerror = () => reject(new Error('Failed to read file'));
    reader.readAsArrayBuffer(file);
  });
}

function toApiRow(tx: ParsedTransaction): BankStatementRow {
  const details = [
    tx.name,
    tx.phone,
    tx.mpesaCode,
    tx.rawDetails,
  ].filter(Boolean).join(' - ');

  return {
    trans_date: tx.date,
    value_date: tx.date,
    transaction_details: details,
    reference_no: tx.reference,
    credit: tx.amount,
    debit: 0,
    book_balance: 0,
  };
}

interface AggregatedResult {
  totalNewMembers: number;
  totalMatched: number;
  totalContributionsCreated: number;
  totalContributionsUpdated: number;
  totalDuplicates: number;
  totalUnmatched: number;
  totalInvalidPhone: number;
  totalImported: number;
  totalAmount: number;
  allFailures: { row: number; reason: string }[];
  batchIds: string[];
}

function aggregateResults(results: ImportBatchResponse[]): AggregatedResult {
  return results.reduce(
    (acc, r) => ({
      totalNewMembers: acc.totalNewMembers + r.new_members,
      totalMatched: acc.totalMatched + r.matched_members,
      totalContributionsCreated: acc.totalContributionsCreated + r.contributions_created,
      totalContributionsUpdated: acc.totalContributionsUpdated + r.contributions_updated,
      totalDuplicates: acc.totalDuplicates + r.duplicates,
      totalUnmatched: acc.totalUnmatched + r.unmatched,
      totalInvalidPhone: acc.totalInvalidPhone + r.invalid_phone,
      totalImported: acc.totalImported + r.imported,
      totalAmount: acc.totalAmount + r.amount,
      allFailures: [...acc.allFailures, ...r.failures],
      batchIds: [...acc.batchIds, r.batch_id],
    }),
    {
      totalNewMembers: 0,
      totalMatched: 0,
      totalContributionsCreated: 0,
      totalContributionsUpdated: 0,
      totalDuplicates: 0,
      totalUnmatched: 0,
      totalInvalidPhone: 0,
      totalImported: 0,
      totalAmount: 0,
      allFailures: [] as { row: number; reason: string }[],
      batchIds: [] as string[],
    },
  );
}

export function BankStatementImportPage() {
  const [file, setFile] = useState<File | null>(null);
  const [parseResult, setParseResult] = useState<ParseResult | null>(null);
  const [parseError, setParseError] = useState<string | null>(null);
  const [parsing, setParsing] = useState(false);
  const [importResult, setImportResult] = useState<AggregatedResult | null>(null);
  const [importError, setImportError] = useState<string | null>(null);
  const [importing, setImporting] = useState(false);
  const [currentChunk, setCurrentChunk] = useState(0);
  const [totalChunks, setTotalChunks] = useState(0);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const qc = useQueryClient();

  const parseMut = useMutation({
    mutationFn: (f: File) => parseWorkbook(f),
    onSuccess: (result) => {
      setParseResult(result);
      setParseError(null);
    },
    onError: (err) => {
      setParseError(err instanceof Error ? err.message : 'Failed to parse file');
      setParseResult(null);
    },
  });

  const handleFileSelect = useCallback((f: File) => {
    setFile(f);
    setParseResult(null);
    setParseError(null);
    setImportResult(null);
    setImportError(null);
    setParsing(true);
    parseMut.mutate(f);
    setParsing(false);
  }, [parseMut]);

  const handleImport = useCallback(async () => {
    if (!parseResult || !file) return;
    setImporting(true);
    setImportError(null);
    setImportResult(null);

    const apiRows = parseResult.rows.map(toApiRow);
    const chunkCount = Math.ceil(apiRows.length / 200);
    setTotalChunks(chunkCount);
    setCurrentChunk(0);

    try {
      const results: ImportBatchResponse[] = [];
      for (let i = 0; i < chunkCount; i++) {
        setCurrentChunk(i + 1);
        const chunk = apiRows.slice(i * 200, (i + 1) * 200);
        const { importsApi } = await import('@/api/imports');
        const res = await importsApi.bankStatement(chunk, {
          source_name: parseResult.sheetName,
          file_name: file.name,
        });
        results.push(...res);
      }

      const aggregated = aggregateResults(results);
      setImportResult(aggregated);

      qc.invalidateQueries({ queryKey: ['treasurer'] });
      qc.invalidateQueries({ queryKey: queryKeys.treasurerBankStatement(1) });
      qc.invalidateQueries({ queryKey: ['admin', 'members'] });
      qc.invalidateQueries({ queryKey: ['admin', 'dashboard'] });
    } catch (err) {
      setImportError(err instanceof Error ? err.message : 'Import failed');
    } finally {
      setImporting(false);
    }
  }, [parseResult, file, qc]);

  const handleReset = () => {
    setFile(null);
    setParseResult(null);
    setParseError(null);
    setImportResult(null);
    setImportError(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const previewRows = parseResult?.rows.slice(0, 30) || [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Import Bank Statement</h1>
          <p className="mt-1 text-sm text-neutral-500">Upload a bank statement spreadsheet to parse, preview, and import transactions</p>
        </div>
        {parseResult && (
          <Button variant="secondary" onClick={() => downloadCSV('bank-statement-preview.csv', parseResult.rows.map((r) => ({ date: r.date, name: r.name, phone: r.phone, reference: r.reference, amount: r.amount, mpesa_code: r.mpesaCode, details: r.rawDetails })))}>
            <Download size={15} /> Export Preview
          </Button>
        )}
      </div>

      {/* File Upload */}
      {!parseResult && !importResult && (
        <Card className="p-8">
          <div
            className="border-2 border-dashed border-neutral-200 rounded-n26-card p-12 text-center hover:border-primary-300 transition-colors cursor-pointer"
            onClick={() => fileInputRef.current?.click()}
            onDrop={(e) => { e.preventDefault(); const f = e.dataTransfer.files?.[0]; if (f) handleFileSelect(f); }}
            onDragOver={(e) => e.preventDefault()}
          >
            {parsing ? (
              <>
                <Loader2 size={36} className="mx-auto text-primary-500 mb-4 animate-spin" />
                <p className="text-sm text-neutral-500">Parsing spreadsheet...</p>
              </>
            ) : (
              <>
                <div className="w-16 h-16 rounded-2xl bg-primary-50 flex items-center justify-center mx-auto mb-4">
                  <FileSpreadsheet size={28} className="text-primary-600" />
                </div>
                <p className="text-sm font-medium text-neutral-700 mb-2">
                  Drop your .xlsx or .xls file here, or click to browse
                </p>
                <p className="text-xs text-neutral-400 max-w-md mx-auto">
                  The file is parsed in your browser. No data is uploaded until you press Import.
                  The parser looks for Date, Narration/Details, and Credit/Amount columns.
                </p>
              </>
            )}
            <input ref={fileInputRef} type="file" accept=".xlsx,.xls" onChange={(e) => { const f = e.target.files?.[0]; if (f) handleFileSelect(f); }} className="hidden" />
          </div>
          {parseError && (
            <div className="mt-4 p-4 rounded-xl bg-error-50 border border-error-200 flex items-start gap-3">
              <div className="w-9 h-9 rounded-lg bg-error-100 flex items-center justify-center flex-shrink-0">
                <AlertTriangle size={18} className="text-error-600" />
              </div>
              <div>
                <p className="text-sm font-semibold text-error-700">Could not parse file</p>
                <p className="text-xs text-error-600 mt-1">{parseError}</p>
              </div>
            </div>
          )}
        </Card>
      )}

      {/* Parse Summary */}
      {parseResult && !importResult && (
        <Card className="p-6">
          <div className="flex items-center justify-between mb-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-success-50 flex items-center justify-center">
                <CheckCircle size={20} className="text-success-600" />
              </div>
              <div>
                <h3 className="text-base font-bold text-neutral-900">File Parsed Successfully</h3>
                <p className="text-xs text-neutral-400">{parseResult.sheetName} · Header at row {parseResult.headerRow} · {parseResult.rawRowCount} raw rows</p>
              </div>
            </div>
            <button onClick={handleReset} className="text-xs text-neutral-500 hover:text-neutral-700 font-medium">Choose different file</button>
          </div>

          <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
            {[
              { label: 'Valid Transactions', value: String(parseResult.rows.length), accent: 'text-neutral-900' },
              { label: 'Total Amount', value: formatCurrency(parseResult.totalAmount), accent: 'text-success-600' },
              { label: 'Duplicates Removed', value: String(parseResult.duplicatesRemoved), accent: 'text-neutral-700' },
              { label: 'Skipped Rows', value: String(parseResult.skippedRows), accent: 'text-warning-600' },
              { label: 'Batches', value: String(Math.ceil(parseResult.rows.length / 200)), accent: 'text-neutral-700' },
            ].map(s => (
              <div key={s.label} className="bg-neutral-50 rounded-xl p-3">
                <p className="text-2xs text-neutral-400 font-medium uppercase tracking-wider">{s.label}</p>
                <p className={classNames('text-lg font-bold mt-1', s.accent)}>{s.value}</p>
              </div>
            ))}
          </div>

          {parseResult.rows.length > 0 && (
            <>
              <div className="flex items-center justify-between mb-3">
                <h4 className="text-xs font-bold text-neutral-700">Preview ({Math.min(previewRows.length, 30)} of {parseResult.rows.length})</h4>
                <Badge status="confirmed">{Math.ceil(parseResult.rows.length / 200)} batch{Math.ceil(parseResult.rows.length / 200) > 1 ? 'es' : ''}</Badge>
              </div>
              <div className="overflow-x-auto -mx-6 px-6">
                <table className="w-full min-w-[640px]">
                  <thead>
                    <tr className="border-b border-neutral-100">
                      <th className="text-left py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider">Date</th>
                      <th className="text-left py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider">Name</th>
                      <th className="text-left py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider">Phone</th>
                      <th className="text-left py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider">Reference</th>
                      <th className="text-right py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider">Amount</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-neutral-50">
                    {previewRows.map((tx, i) => (
                      <tr key={i} className="hover:bg-neutral-50/60 transition-colors">
                        <td className="py-3 px-2 text-sm text-neutral-500 whitespace-nowrap">{formatDate(tx.date)}</td>
                        <td className="py-3 px-2 text-sm font-medium text-neutral-800">{tx.name}</td>
                        <td className="py-3 px-2 text-sm text-neutral-500 font-mono">{tx.phone}</td>
                        <td className="py-3 px-2 text-xs text-neutral-400 font-mono">{tx.reference}</td>
                        <td className="py-3 px-2 text-right text-sm font-semibold text-success-600 whitespace-nowrap">{formatCurrency(tx.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {parseResult.rows.length > 30 && <p className="text-xs text-neutral-400 mt-2 text-center">Showing first 30 of {parseResult.rows.length} transactions</p>}

              <div className="flex gap-3 mt-5 pt-4 border-t border-neutral-100">
                <Button onClick={handleImport} disabled={importing} className="flex-1">
                  {importing ? (<><Loader2 size={16} className="animate-spin" /> Importing batch {currentChunk} of {totalChunks}...</>) : (<><ArrowRight size={16} /> Import {parseResult.rows.length} transactions</>)}
                </Button>
                <Button variant="secondary" onClick={handleReset} disabled={importing}>Cancel</Button>
              </div>
            </>
          )}

          {parseResult.rows.length === 0 && (
            <div className="text-center py-8">
              <div className="w-12 h-12 rounded-xl bg-warning-50 flex items-center justify-center mx-auto mb-3">
                <AlertTriangle size={24} className="text-warning-500" />
              </div>
              <p className="text-sm text-neutral-600 max-w-md mx-auto">No valid transactions found. The parser needs rows with a date, positive credit/deposit amount, and a Kenyan phone number in the narration or a phone column.</p>
            </div>
          )}
        </Card>
      )}

      {/* Import Error */}
      {importError && (
        <Card className="p-6">
          <div className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-xl bg-error-50 flex items-center justify-center flex-shrink-0">
              <XCircle size={20} className="text-error-600" />
            </div>
            <div>
              <p className="text-sm font-semibold text-error-700">Import failed</p>
              <p className="text-xs text-error-600 mt-1">{importError}</p>
              <Button variant="secondary" size="sm" className="mt-3" onClick={handleReset}>Try Again</Button>
            </div>
          </div>
        </Card>
      )}

      {/* Import Results */}
      {importResult && (
        <Card className="p-6">
          <div className="flex items-center gap-3 mb-5">
            <div className="w-10 h-10 rounded-xl bg-success-50 flex items-center justify-center">
              <CheckCircle size={20} className="text-success-600" />
            </div>
            <h3 className="text-base font-bold text-neutral-900">Import Complete</h3>
          </div>

          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            {[
              { label: 'New Members', value: importResult.totalNewMembers, bg: 'bg-success-50', text: 'text-success-700' },
              { label: 'Matched', value: importResult.totalMatched, bg: 'bg-primary-50', text: 'text-primary-700' },
              { label: 'Contributions Created', value: importResult.totalContributionsCreated, bg: 'bg-neutral-50', text: 'text-neutral-800' },
              { label: 'Contributions Updated', value: importResult.totalContributionsUpdated, bg: 'bg-neutral-50', text: 'text-neutral-800' },
              { label: 'Duplicates Skipped', value: importResult.totalDuplicates, bg: 'bg-neutral-50', text: 'text-neutral-600' },
              { label: 'Unmatched', value: importResult.totalUnmatched, bg: 'bg-warning-50', text: 'text-warning-700' },
              { label: 'Total Imported', value: importResult.totalImported, bg: 'bg-neutral-50', text: 'text-neutral-800' },
              { label: 'Total Amount', value: formatCurrency(importResult.totalAmount), bg: 'bg-success-50', text: 'text-success-700' },
            ].map(s => (
              <div key={s.label} className={classNames('rounded-xl p-3', s.bg)}>
                <p className={classNames('text-2xs font-medium uppercase tracking-wider', s.text, 'opacity-70')}>{s.label}</p>
                <p className={classNames('text-xl font-bold mt-1', s.text)}>{s.value}</p>
              </div>
            ))}
          </div>

          {importResult.batchIds.length > 0 && (
            <div className="mb-4 p-3 bg-neutral-50 rounded-xl">
              <p className="text-xs text-neutral-500">Batch ID{importResult.batchIds.length > 1 ? 's (last shown)' : ''}: <span className="font-mono text-neutral-700">{importResult.batchIds[importResult.batchIds.length - 1]}</span></p>
            </div>
          )}

          {importResult.allFailures.length > 0 && (
            <div className="mb-4">
              <p className="text-xs font-bold text-error-600 mb-2">Row Failures ({importResult.allFailures.length})</p>
              <div className="max-h-32 overflow-y-auto border border-error-100 rounded-xl">
                {importResult.allFailures.slice(0, 20).map((f, i) => (
                  <div key={i} className="px-3 py-1.5 text-xs text-error-600 border-b border-error-50 last:border-0">Row {f.row}: {f.reason}</div>
                ))}
                {importResult.allFailures.length > 20 && <p className="px-3 py-1.5 text-xs text-neutral-400">...and {importResult.allFailures.length - 20} more</p>}
              </div>
            </div>
          )}

          <div className="p-3 rounded-xl bg-secondary-50 border border-secondary-100 mb-4">
            <p className="text-xs text-secondary-700">
              New members receive a default password of <span className="font-mono font-bold">Member2026</span> and must change it on first login. Contribution allocation starts in the transaction month and spreads forward at the configured monthly amount.
            </p>
          </div>

          <Button onClick={handleReset}>Import Another File</Button>
        </Card>
      )}
    </div>
  );
}
