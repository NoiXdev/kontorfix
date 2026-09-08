/**
 * The German copy for `oci_auto_create_repositories` — the push-time repository creation
 * toggle — shared by the two admin pages that offer it: the instance-wide ceiling on
 * `admin/system/Index.vue` and the per-organization narrowing on
 * `admin/organizations/Show.vue`.
 *
 * The module exists for the reason the portal's own copy modules give (see
 * `components/kontorfix/portalSetupBand.ts`): this frontend has no component runner, so a
 * sentence left inside a `.vue` file is a sentence nothing verifies. Here there is a second
 * reason on top of it — the LABEL is quoted back at the operator by the server, in
 * `OciException::nameUnknownAutoCreateDisabled()`'s refusal message, which names the switch
 * to look for. Three copies of one label drift; one of them then sends the operator hunting
 * for a checkbox whose caption no longer matches.
 */

/**
 * The switch's caption. Kept verbatim in step with
 * `App\Exceptions\OciException::nameUnknownAutoCreateDisabled()`, which prints it inside the
 * NAME_UNKNOWN message a refused `docker push` receives.
 */
export const OCI_AUTO_CREATE_LABEL = 'Repositories beim Push anlegen';

/**
 * What switching it on costs, stated where it is switched on.
 *
 * A `docker push` creates the repository row at its FIRST request — `POST
 * .../blobs/uploads/` — long before any layer, let alone a manifest, has arrived. A client
 * that opens sessions and never finishes them therefore leaves fully-fledged, empty
 * `packages` rows behind: they count in package lists, in the dashboard's totals and in the
 * customer portal until the storage sweeper reclaims them, which it does only once they are
 * older than the grace period and still hold no image (see OciSweeper — the rows carry
 * `auto_created_at` precisely so the sweeper can tell them from repositories an operator
 * registered on purpose). That is a deliberate trade (the alternative is a repository that
 * does not exist until a push completes, which no client can address in the meantime), but
 * the interval between the broken push and the next sweep is still not one an operator
 * should discover from a package list that grew overnight.
 */
export const OCI_AUTO_CREATE_COST =
    'Zu bedenken: Das Repository entsteht bereits beim ersten Upload-Request, nicht erst mit dem fertigen Image. ' +
    'Abgebrochene Pushes hinterlassen daher leere Repository-Einträge, die in Paketlisten, Zählungen und im ' +
    'Kundenportal auftauchen. Die Speicherbereinigung entfernt sie wieder, sobald sie älter als die Schonfrist ' +
    'sind und weiterhin kein Image enthalten.';
