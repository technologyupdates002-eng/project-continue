import { api } from './client';

export interface BankStatementRow {
  trans_date: string;
  value_date: string;
  transaction_details: string;
  reference_no: string;
  credit: number;
  debit: number;
  book_balance: number;
}

export interface ImportBatchResponse {
  batch_id: string;
  new_members: number;
  matched_members: number;
  contributions_created: number;
  contributions_updated: number;
  duplicates: number;
  unmatched: number;
  invalid_phone: number;
  imported: number;
  amount: number;
  failures: { row: number; reason: string }[];
}

const CHUNK_SIZE = 200;

export const importsApi = {
  bankStatement: async (
    rows: BankStatementRow[],
    meta: { source_name?: string; file_name?: string },
  ): Promise<ImportBatchResponse[]> => {
    const results: ImportBatchResponse[] = [];
    const chunks: BankStatementRow[][] = [];

    for (let i = 0; i < rows.length; i += CHUNK_SIZE) {
      chunks.push(rows.slice(i, i + CHUNK_SIZE));
    }

    for (const chunk of chunks) {
      const res = await api.post<ImportBatchResponse>(
        '/treasurer/bank-statement-import',
        {
          rows: chunk.map((r) => [
            r.trans_date,
            r.value_date,
            r.transaction_details,
            r.reference_no,
            r.credit,
            r.debit,
            r.book_balance,
          ]),
          source_name: meta.source_name,
          file_name: meta.file_name,
        },
      );
      results.push(res);
    }

    return results;
  },
};
