/** Достаёт текст ошибки из ответа API: { "error": "..." } или стандартный текст. */
export function describeError(error: unknown): string {
  const fallback = 'Не удалось выполнить запрос';

  if (typeof error !== 'object' || error === null) {
    return fallback;
  }

  const payload = (error as { error?: unknown }).error;

  if (typeof payload === 'object' && payload !== null) {
    const message = (payload as { error?: unknown }).error;

    if (typeof message === 'string' && message.trim() !== '') {
      return message;
    }
  }

  if (typeof (error as { message?: unknown }).message === 'string') {
    return fallback;
  }

  return fallback;
}
