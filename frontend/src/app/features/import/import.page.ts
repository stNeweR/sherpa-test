import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { DecimalPipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTableModule } from '@angular/material/table';
import { MatToolbarModule } from '@angular/material/toolbar';
import { RouterLink } from '@angular/router';
import { Store } from '@ngrx/store';

import { JOB_STATE_LABELS, ImportError } from '../../core/models/import-job.model';
import { AppIcon } from '../../ui/icon';
import { ImportActions } from '../../store/import/import.actions';
import {
  ImportState,
  selectError,
  selectJob,
  selectPolling,
  selectUploading,
} from '../../store/import/import.reducer';

@Component({
  selector: 'app-import-page',
  imports: [
    DecimalPipe,
    RouterLink,
    AppIcon,
    MatButtonModule,
    MatCardModule,
    MatProgressBarModule,
    MatProgressSpinnerModule,
    MatTableModule,
    MatToolbarModule,
  ],
  templateUrl: './import.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportPage {
  private readonly store = inject(Store<ImportState>);

  protected readonly job = toSignal(this.store.select(selectJob), { initialValue: null });
  protected readonly uploading = toSignal(this.store.select(selectUploading), {
    initialValue: false,
  });
  protected readonly polling = toSignal(this.store.select(selectPolling), { initialValue: false });
  protected readonly error = toSignal(this.store.select(selectError), { initialValue: null });

  protected readonly stateLabel = computed(() => {
    const state = this.job()?.state;

    return state === undefined ? '' : JOB_STATE_LABELS[state];
  });

  protected readonly progress = computed(() => {
    const job = this.job();

    if (job === null || job.state === 'queued') {
      return 0;
    }

    const report = job.report;

    if (job.state === 'completed' && report !== null && report.total > 0) {
      return 100;
    }

    return Math.min(job.processed, 100);
  });

  protected readonly errors = computed<ImportError[]>(() => this.job()?.report?.errors ?? []);

  protected onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file === undefined) {
      return;
    }

    this.store.dispatch(ImportActions.uploadRequested({ file }));
    input.value = '';
  }

  protected reset(): void {
    this.store.dispatch(ImportActions.reset());
  }

  protected trackByRow(_: number, error: ImportError): string {
    return `${error.row_number}:${error.code}`;
  }
}
