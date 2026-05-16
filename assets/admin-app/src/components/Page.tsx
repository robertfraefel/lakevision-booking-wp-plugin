interface PageProps {
  title: string;
  actions?: React.ReactNode;
  children: React.ReactNode;
}

export function Page({ title, actions, children }: PageProps) {
  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      <header className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 py-3 border-b border-gray-200">
        <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
        {actions && <div className="flex items-center gap-2">{actions}</div>}
      </header>
      <div className="p-4">{children}</div>
    </div>
  );
}
