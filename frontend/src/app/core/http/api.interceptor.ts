import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { AuthService } from '../auth/auth.service';

/**
 * Добавляет Bearer-токен к запросам API и уводит на страницу входа при 401.
 */
export const apiInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = auth.token();

  const request =
    token === null ? req : req.clone({ setHeaders: { Authorization: `Bearer ${token}` } });

  return next(request).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401) {
        auth.logout();
        void router.navigate(['/login']);
      }

      return throwError(() => error);
    }),
  );
};
