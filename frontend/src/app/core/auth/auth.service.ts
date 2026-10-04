import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';

export interface LoginResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
}

const TOKEN_KEY = 'sherpa.access_token';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);

  private readonly tokenSignal = signal<string | null>(readToken());
  private readonly expiresAtSignal = signal<number | null>(readExpiry());

  readonly token = this.tokenSignal.asReadonly();
  readonly isAuthenticated = computed(() => {
    const expiresAt = this.expiresAtSignal();

    return this.tokenSignal() !== null && (expiresAt === null || expiresAt > Date.now());
  });

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>('/api/auth/login', { email, password })
      .pipe(tap((response) => this.store(response)));
  }

  logout(): void {
    this.tokenSignal.set(null);
    this.expiresAtSignal.set(null);

    if (typeof localStorage === 'undefined') {
      return;
    }

    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(`${TOKEN_KEY}.expires_at`);
  }

  private store(response: LoginResponse): void {
    this.tokenSignal.set(response.access_token);
    this.expiresAtSignal.set(Date.now() + response.expires_in * 1000);

    if (typeof localStorage === 'undefined') {
      return;
    }

    localStorage.setItem(TOKEN_KEY, response.access_token);
    localStorage.setItem(
      `${TOKEN_KEY}.expires_at`,
      String(Date.now() + response.expires_in * 1000),
    );
  }
}

function readToken(): string | null {
  return typeof localStorage === 'undefined' ? null : localStorage.getItem(TOKEN_KEY);
}

function readExpiry(): number | null {
  if (typeof localStorage === 'undefined') {
    return null;
  }

  const raw = localStorage.getItem(`${TOKEN_KEY}.expires_at`);

  return raw === null ? null : Number.parseInt(raw, 10);
}
