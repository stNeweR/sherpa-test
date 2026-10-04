import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ImportJob } from '../models/import-job.model';

@Injectable({ providedIn: 'root' })
export class ImportApiService {
  private readonly http = inject(HttpClient);

  upload(file: File): Observable<ImportJob> {
    const body = new FormData();
    body.append('file', file, file.name);

    return this.http.post<ImportJob>('/api/imports', body);
  }

  status(jobId: string): Observable<ImportJob> {
    return this.http.get<ImportJob>(`/api/imports/${encodeURIComponent(jobId)}`);
  }
}
