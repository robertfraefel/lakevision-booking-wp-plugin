import { Page } from '../components/Page';

interface PlaceholderProps {
  title: string;
  hint?: string;
}

export function Placeholder({ title, hint }: PlaceholderProps) {
  return (
    <Page title={title}>
      <div className="py-8 text-center text-gray-500 text-sm">
        <div className="text-3xl mb-2">🚧</div>
        <div className="font-medium text-gray-700">Modul folgt in einer späteren Phase</div>
        {hint && <div className="mt-1 text-xs">{hint}</div>}
      </div>
    </Page>
  );
}
