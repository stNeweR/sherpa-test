import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { ActivatedRoute, Router } from '@angular/router';

import { AuthService } from '../../core/auth/auth.service';
import { describeError } from '../../core/http/error-message';
import { AppIcon } from '../../ui/icon';

@Component({
  selector: 'app-login-page',
  imports: [
    FormsModule,
    AppIcon,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatProgressSpinnerModule,
  ],
  templateUrl: './login.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly email = signal('');
  protected readonly password = signal('');
  protected readonly error = signal<string | null>(null);
  protected readonly submitting = signal(false);

  constructor() {
    if (this.auth.isAuthenticated()) {
      void this.router.navigate(['/products']);
    }
  }

  protected submit(): void {
    if (this.submitting()) {
      return;
    }

    this.error.set(null);
    this.submitting.set(true);

    this.auth.login(this.email(), this.password()).subscribe({
      next: () => {
        this.submitting.set(false);
        void this.router.navigateByUrl(this.returnUrl());
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.error.set(describeError(error));
      },
    });
  }

  protected onEmail(value: string): void {
    this.email.set(value);
  }

  protected onPassword(value: string): void {
    this.password.set(value);
  }

  protected useDemoCredentials(): void {
    this.email.set('admin@example.com');
    this.password.set('admin_secret');
  }

  private returnUrl(): string {
    const target = this.route.snapshot.queryParamMap.get('returnUrl');

    return target !== null && target.startsWith('/') ? target : '/products';
  }
}
