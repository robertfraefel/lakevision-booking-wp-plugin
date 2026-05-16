import { Page } from '../components/Page';

export function NoAccess() {
  return (
    <Page title="Kein Zutritt">
      <p className="text-sm text-gray-600">
        Du hast keine Berechtigung für diesen Bereich. Bitte wende dich an die Inhaberin,
        wenn du Zugriff brauchst.
      </p>
    </Page>
  );
}
