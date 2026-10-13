try {
  const response = await fetch(`http://127.0.0.1:${Number(process.env.PORT || 8789)}/health`, { signal: AbortSignal.timeout(3000) });
  process.exitCode = response.ok ? 0 : 1;
} catch { process.exitCode = 1; }
