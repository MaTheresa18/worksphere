# Technical Architecture and

# Implementation Strategy: Analyzing

# Mozilla Thunderbird’s IMAP

# Synchronization for High-Performance

# PHP Applications

## 1. Introduction: The Thunderbird Paradigm in Open

## Source Email Architecture

The landscape of open-source communication software is dominated by Mozilla Thunderbird,
a project that has evolved from a component of the monolithic Mozilla Suite into a standalone,
sophisticated Personal Information Manager (PIM). For software architects and developers
aiming to construct robust email applications—specifically within a PHP
environment—Thunderbird represents the gold standard of IMAP implementation. It addresses
the fundamental challenges of email synchronization: high latency, massive data volumes,
intermittent connectivity, and the complexity of state management across distributed systems.

This report provides an exhaustive analysis of Thunderbird’s internal architecture, focusing on
its handling of the Internet Message Access Protocol (IMAP). It specifically addresses the
mechanisms of message retrieval, the logic of "forward" and "backward" data synchronization
(crawling), and the repository ecosystem that supports its development. Furthermore, it
translates these C++/XPCOM architectural patterns into actionable strategies for modern PHP
application development, leveraging libraries like Horde_Imap_Client and event-driven
architectures to replicate Thunderbird's reliability and performance.

The analysis draws upon a deep examination of Thunderbird’s source code repositories,
developer documentation, and architectural decision records. It synthesizes these findings to
guide the creation of a "Thunderbird-class" synchronization engine in PHP, moving beyond
basic polling scripts to sophisticated, state-aware daemons.

## 2. The Repository Ecosystem and Source Code

## Management

Understanding Thunderbird’s architecture begins with navigating its complex source code
management system. Unlike many modern projects that exist as single, self-contained Git
repositories, Thunderbird operates within a federated ecosystem that mirrors the Mozilla


platform's historical structure.

### 2.1. The Comm-Central vs. Mozilla-Central Duality

The core of Thunderbird’s desktop application does not reside in a standard GitHub
repository in the way many developers might expect. While the organization maintains a
presence on GitHub, the authoritative source code is managed via Mercurial in a repository
known as comm-central.

Thunderbird is technically a "consumer" of the Mozilla platform (Gecko). To build the
application, the build system requires two distinct codebases to be fused:

1. **mozilla-central:** This contains the core rendering engine, networking stack (Necko),
    security libraries (NSS), and the JavaScript engine (SpiderMonkey). It is the same
    foundation used by the Firefox browser.
2. **comm-central:** This repository sits as a subdirectory (typically named comm/) within the
    source tree. It contains the specific logic for Mail, News, and Calendar functions. This
    includes the backend code for IMAP, POP3, and SMTP, as well as the frontend user
    interface logic written in XUL/XHTML and JavaScript.^1

The repository listed on GitHub as thunderbird/thunderbird-desktop is primarily a read-only
mirror or a coordination point for specific patches, rather than the active site of daily
development logic which occurs in the Mercurial environment.^1 This distinction is critical for
developers attempting to audit the code; the directory structures and build instructions (e.g.,
using the mach command) referenced in documentation apply to this composite source tree.^4

### 2.2. The GitHub Repository Ecosystem

Despite the centralization of the desktop core in Mercurial, the thunderbird organization on
GitHub is robust, hosting over 108 repositories that manage the broader ecosystem.^5 This
decentralized approach allows for faster iteration on peripheral tools and mobile applications.

#### 2.2.1. Mobile and Modernization Initiatives

A significant portion of recent activity is concentrated in the mobile space. The
thunderbird-android repository, formerly known as K-9 Mail, represents a modern,
Kotlin-based approach to email on Android.^6 Unlike the legacy C++ desktop codebase, this
project uses standard Gradle build systems and GitHub Pull Request workflows, making it a
more accessible reference for modern architectural patterns.^7 Similarly, thunderbird-ios uses
Swift, adhering to the platform-specific paradigms of the Apple ecosystem.^6

#### 2.2.2. Infrastructure and Localization

The GitHub organization also houses critical infrastructure code:

```
● thunderbird-website: Manages the public-facing web properties.
```

```
● thunderbird-l10n: Handles the massive task of localizing Thunderbird into dozens of
languages, integrating with Fluent and Gettext systems.^5
● developer-docs: Contains the Markdown source for the documentation hosted on
developer.thunderbird.net.^4
```
**Table 1: Key Thunderbird Repositories and Technologies**

```
Repository / Project Primary Function Primary Languages Ecosystem Role
```
```
comm-central Core Desktop
Application
```
```
C++, JavaScript, XUL,
Rust
```
```
The authoritative
"Backend" and
"Frontend" of
Thunderbird Desktop.
```
```
thunderbird-androi
d
```
```
Android Client Kotlin The future of
Thunderbird on mobile
(formerly K-9 Mail).
```
```
thunderbird-ios iOS Client Swift Native iOS
implementation.
```
```
ews-rs Exchange Support Rust Modern
implementation of
Exchange Web
Services protocols.
```
```
webext-docs API Documentation Python, Markdown Documentation for the
MailExtensions API.
```
1

## 3. The Architecture of IMAP Synchronization: Logic


## and Mechanisms

To replicate Thunderbird’s capabilities in a PHP application, one must first dissect the logic
governing its IMAP interactions. Thunderbird does not merely "fetch" email; it maintains a
synchronized state between a local cache and the remote server using a complex interplay of
network protocols and state managers.

### 3.1. The Network Layer: nsImapProtocol

The heavy lifting of IMAP communication is performed by the nsImapProtocol class, located
within mailnews/imap/src/nsImapProtocol.cpp.^8 This C++ class encapsulates the state
machine required to converse with an IMAP server. It handles the low-level socket operations,
SSL/TLS encryption handshake, and the parsing of the server's text-based responses.

#### 3.1.1. Connection Management

Thunderbird employs a connection pooling strategy managed by nsImapIncomingServer.^10
Unlike a typical PHP script that opens a connection, fetches data, and closes the connection,
Thunderbird maintains persistent connections.

```
● Caching: Connections are cached and reused. If a user switches folders, Thunderbird
attempts to "steal" an existing idle connection rather than incurring the overhead of a
new TCP handshake and SSL negotiation.^11
● Concurrency: To prevent blocking the user interface (UI), Thunderbird opens multiple
connections to the same server—typically up to five. One connection might be dedicated
to the folder the user is currently viewing, while others perform background
synchronization or check for new mail in other folders.^12
```
#### 3.1.2. The Fetching Strategy

Thunderbird rarely fetches an entire email message in a single operation unless specifically
instructed to download for offline use. The standard fetch logic follows a "Headers First"
approach 14 :

1. **Discovery:** The client asks for a list of message UIDs and Flags (UID FETCH 1:* (FLAGS)).
2. **Header Retrieval:** For new UIDs, it requests the message headers (FETCH BODY.PEEK).
    This provides the Sender, Subject, and Date information required to populate the UI list.
3. **On-Demand Body Fetch:** The message body (FETCH BODY.PEEK) or specific MIME
    parts are only requested when the user clicks on the message, unless "AutoSync" is
    active.^15
4. **Chunking:** Large fetches are chunked. The source code reveals hard-coded chunk sizes
    (e.g., 65,536 bytes) to manage memory usage during the download of large
    attachments.^16

### 3.2. Forward and Backward Synchronization: The "Crawler" Analogy


The user query introduces the concept of "forward and backward crawlers." In the context of
Thunderbird, this maps to two distinct synchronization behaviors: **Forward Synchronization**
(Real-time/Incoming) and **Backward Synchronization** (Backfilling/History).

#### 3.2.1. Forward Synchronization (The Real-Time Listener)

The "Forward Crawler" equivalent in Thunderbird is the implementation of **IMAP IDLE** (RFC
2177).

```
● Mechanism: Once a folder is selected, nsImapProtocol issues the IDLE command. The
server holds the connection open and pushes unsolicited updates (e.g., * 23 EXISTS, * 5
RECENT) immediately upon message arrival.^17
● Fallback: If the server does not support IDLE, Thunderbird reverts to a polling
mechanism, checking for new messages at a user-defined interval (e.g., every 10
minutes).
● Implementation: This ensures that the local client always reflects the "tip" of the mailbox
state in near real-time.
```
#### 3.2.2. Backward Synchronization (The Historic Backfill)

The "Backward Crawler" equivalent is the **AutoSync** system, governed by
nsAutoSyncManager.^18 This system is responsible for the background replication of message
bodies to the local disk (Offline Store).

```
● Logic: When the user is idle, or when network bandwidth permits, nsAutoSyncManager
scans the local folder database for messages that have headers but no bodies (offline
flag not set).
● Priority Queue: It does not simply download everything linearly. It uses a priority queue
strategy (nsAutoSyncFolderStrategy) to determine which messages to download first.
Typically, this prioritizes the most recent messages ("Newest First") and the folder
currently being viewed by the user.^19
● Chaining: To avoid saturating the connection pool, it chains download requests. It
processes one folder per IMAP server at a time (Chained Mode) or, in aggressive
scenarios, multiple folders simultaneously (Parallel Mode).^18
```
### 3.3. Protocol Optimization: QRESYNC and CONDSTORE

A critical insight for any PHP developer mimicking this architecture is the importance of
**QRESYNC** (RFC 7162). Traditional IMAP sync is inefficient; to find what changed, a client must
fetch all flags for all messages and compare them to the local database.

Thunderbird supports CONDSTORE and QRESYNC to optimize this.^22

```
● MODSEQ: The server assigns a "Modification Sequence" number to every change.
● QRESYNC: When opening a folder, Thunderbird sends its last known HIGHESTMODSEQ.
The server responds only with the messages that have changed (flags updated, deleted,
```

```
or added) since that sequence number.^24
● Impact: This reduces the data transfer for a folder sync from megabytes (fetching all
flags) to mere bytes (fetching only changes), a crucial optimization for mobile or
web-based clients with massive mailboxes.^24
```
## 4. Translating Thunderbird Architecture to PHP

Developing a Thunderbird-class synchronization engine in PHP requires a paradigm shift.
Standard PHP execution is request-bound: a script starts, executes, and dies. An IMAP client,
however, requires persistence.

### 4.1. The Architecture of a PHP Sync Daemon

To adopt Thunderbird’s "crawler" logic, the PHP application cannot rely on standard
Apache/Nginx web requests. It must implement a **Daemon Architecture**.

#### 4.1.1. The Process Model

```
● The Controller (Web Interface): A standard PHP application (e.g., Laravel, Symfony)
handles user interactions. It reads from a local database (cache) to display emails
instantly, mimicking Thunderbird’s usage of local .msf files.
● The Sync Daemon (CLI): A separate, long-running PHP process (or set of processes)
runs in the background. This process maintains the persistent IMAP connections.
● Event Loop: Using libraries like ReactPHP or Swoole , the daemon implements a
non-blocking I/O loop. This allows a single PHP process to monitor multiple IMAP
connections (via stream_select) simultaneously, reacting to IDLE notifications without
consuming 100% CPU waiting for input.^26
```
### 4.2. Library Selection: Beyond php-imap

The native PHP imap extension is a wrapper around the antiquated c-client library. It is
blocking, difficult to configure for SSL, and lacks support for modern extensions like
QRESYNC.

**Recommendation:** Adopt **Horde_Imap_Client**.

```
● Pure PHP: It creates raw socket connections, allowing full control over the protocol
stream.^29
● Advanced Features: It is the only major PHP library with native support for CONDSTORE
and QRESYNC.^30 This capability is non-negotiable for high-performance synchronization.
● Extensibility: It allows for granular parsing of fetch responses, critical for implementing
the "Headers First" strategy used by Thunderbird.
```
### 4.3. Implementing the "Forward/Backward" Logic in PHP


#### 4.3.1. The Forward Crawler (Real-Time)

Using Horde_Imap_Client within a ReactPHP loop:

1. **Connect & IDLE:** Authenticate and issue the IDLE command.
2. **Listen:** Use the event loop to watch the socket for readability.
3. **React:** When the server sends * EXISTS, immediately interrupt IDLE, fetch the new
    header, save it to the database, and re-issue IDLE.
4. **Push:** Trigger a WebSocket event (e.g., via Pusher or Mercure) to update the user's web
    UI dynamically.^28

#### 4.3.2. The Backward Crawler (Backfill)

This should be a separate "Job Queue" process (e.g., Laravel Horizon):

1. **Discovery:** When a folder is added, perform a UID SEARCH to find all message UIDs.
2. **Gap Analysis:** Compare server UIDs against the local database to identify missing
    ranges.
3. **Batch Processing:** Push "Fetch Jobs" onto a queue. Each job fetches a chunk of
    headers (e.g., 50 at a time).
4. **Priority:** Like nsAutoSyncManager, prioritize the "Inbox" and "Newest" messages. Ensure
    the queue workers process these jobs before tackling the "Archive" folder history.^18

## 5. Data Storage: From Mork to Relational Databases

Thunderbird’s history offers a cautionary tale regarding data storage. For years, it used a
proprietary format called **Mork** for its indices (.msf files), which was notoriously difficult to
parse and prone to corruption.^31

Modern Thunderbird has transitioned to **SQLite** for its "Global Database" (Gloda), enabling
full-text search and cross-folder relationships.^32

### 5.1. Recommended PHP Database Schema

For a PHP application, a relational database (MySQL/PostgreSQL) serves the role of
Thunderbird’s SQLite/Mork layer.

```
● Folders Table: Must store UIDVALIDITY and HIGHESTMODSEQ. If UIDVALIDITY changes
on the server, the local cache for that folder is invalid and must be wiped (a critical
synchronization rule defined in RFC 3501).^31
● Messages Table: Must store the UID, MODSEQ, and FLAGS. Indexes should be optimized
for sorting by UID DESC (to show newest mail fast) and ThreadID (to reconstruct
conversations).
```
## 6. Optimization and Bandwidth Management


Thunderbird employs specific strategies to manage bandwidth that should be adopted:

```
● Pseudo-Offline Operations: When a user deletes a message, Thunderbird marks it
deleted locally and updates the UI immediately ("Optimistic UI"). It then queues the IMAP
operation to be performed in the background.^21 A PHP app should replicate this: an AJAX
request updates the UI and queues a job; the user doesn't wait for the IMAP server to
confirm the delete.
● Chunked Downloads: Never attempt to fetch a 50MB attachment into PHP memory. Use
Horde_Imap_Client's stream handling to pipe the download directly to disk or
S3-compatible storage, mirroring Thunderbird's chunked fetch logic.^16
```
## 7. Security and Authentication: The Modern Standard

Thunderbird has evolved to support **OAuth2** as the primary authentication mechanism for
major providers (Gmail, Outlook), moving away from storing plain-text passwords.^8

For the PHP application:

```
● Token Management: Use a library like league/oauth2-client. Store the refresh_token
securely (encrypted).
● Automatic Renewal: The Sync Daemon must be capable of detecting an authentication
failure (e.g., NO), pausing the sync, refreshing the token via the provider's API, and
resuming the connection seamlessly—logic that is deeply embedded in
nsImapIncomingServer.^8
```
## 8. Conclusion

The architecture of Mozilla Thunderbird provides a comprehensive blueprint for building
high-performance email applications. By dissecting its comm-central codebase, we identify
that the secret to its robustness lies not in simple fetching, but in its sophisticated state
management—specifically the nsImapProtocol for connectivity, nsAutoSyncManager for
prioritized backfilling, and the usage of QRESYNC for efficient differential updates.

For a PHP developer, adopting this architecture means rejecting simple request-response
scripts in favor of a persistent, event-driven daemon architecture using Horde_Imap_Client. By
implementing separate logic for Real-Time Monitoring (Forward Sync) and Historical
Backfilling (Backward Sync), and backing it with a robust relational database that mirrors
Thunderbird's local cache strategies, it is possible to build a web-based email client that rivals
the responsiveness and reliability of a desktop application.

## 9. Detailed Analysis of Thunderbird's Sync Algorithms


### 9.1. The Role of nsAutoSyncManager

The nsAutoSyncManager is the brain behind Thunderbird's offline capabilities. It is
responsible for deciding _what_ to download and _when_. It does not simply download messages
in the order they appear on the server. Instead, it uses a sophisticated heuristic strategy to
maximize the user's perceived performance.

#### 9.1.1. Prioritization Logic

The manager assigns a priority score to different synchronization tasks. This is not explicitly
detailed in every user manual, but the source code reveals the strategy:

1. **Active Folder:** The folder currently open in the UI gets the highest priority. If the user
    clicks "Inbox," the AutoSync manager pauses background downloads of "Archive" to
    service the Inbox immediately.^18
2. **Newest Messages:** Within a folder, newer messages are prioritized. The system assumes
    the user is more interested in an email from today than one from three years ago.^20
3. **Small Messages:** In some configurations, smaller messages are prioritized to quickly
    populate the message preview pane, while larger messages with attachments are
    deferred.^36

#### 9.1.2. Download Chaining

To respect server limits, nsAutoSyncManager organizes downloads into "chains." It ensures
that multiple folders from the _same_ account do not sync simultaneously if it would exceed the
connection limit. However, folders from _different_ accounts can sync in parallel. This logic,
crucial for preventing server-side throttling (e.g., Gmail's "Too many simultaneous
connections" error), must be replicated in any PHP implementation using job queues that are
scoped by account ID.^12

### 9.2. Handling Server Quirks and "Pseudo-Offline" States

Thunderbird's robustness comes from its defensive programming against server
eccentricities.

#### 9.2.1. Pseudo-Offline Operations

One of the most valuable patterns for a PHP developer to adopt is the "Pseudo-Offline"
model. In Thunderbird, if a user moves a message while the network is flaky, the client:

1. Performs the move in the local database immediately.
2. Marks the operation as "pending."
3. A background thread attempts to replay this operation against the IMAP server.^21

This decouples the UI interaction from network latency. In PHP, this translates to:

```
● Frontend: JavaScript optimistically moves the email in the DOM.
```

```
● Backend API: Updates the database state immediately and pushes a job to a Redis
queue.
● Worker: The worker picks up the job and executes the IMAP MOVE command. If it fails
(e.g., socket timeout), the job is released back to the queue with a delay, replicating
Thunderbird's retry logic.^31
```
#### 9.2.2. Folder Repair and State Recovery

Thunderbird includes a "Repair Folder" function because local caches (.msf files) inevitably
drift from the server state due to network interruptions or server-side changes made by other
clients.^31 A PHP application must include a similar "Re-Sync" mechanism. This can be
triggered automatically if the UIDVALIDITY returned by the server does not match the stored
value in the database. When this mismatch is detected, the application must assume its cache
is corrupt, discard the local mapping for that folder, and initiate a fresh discovery process.^31

**Table 2: Synchronization Logic Comparison**

```
Feature Thunderbird
Implementation
(nsAutoSyncManager)
```
```
Recommended PHP
Implementation
```
```
New Mail Detection Persistent IDLE connection
on socket.
```
```
ReactPHP/Swoole loop with
Horde_Imap_Client IDLE.
```
```
History Backfill Background thread, priority
queue (Newest First).
```
```
Redis Job Queue, prioritizing
"Inbox" and high UIDs.
```
```
Deletions/Moves Pseudo-offline (Local apply
-> Replay to server).
```
```
Optimistic UI -> Database
update -> Async Job Queue.
```
```
Conflict Resolution Server is authoritative; Local
cache rebuilt on UIDVALIDITY
change.
```
```
Check UIDVALIDITY; wipe and
re-crawl folder on mismatch.
```

```
Connection Limits Connection pooling (cached
sockets).
```
```
Persistent Daemon with
resource-managed connection
pool.
```
18

### 9.3. Implementing QRESYNC in PHP

The QRESYNC extension is the single most important optimization for syncing large mailboxes.
Without it, a client must fetch the flags of _every_ message in a folder to check for
"Read/Unread" status changes. For a 50,000-message folder, this is slow and
bandwidth-intensive.

#### 9.3.1. The QRESYNC Workflow

A PHP implementation using Horde_Imap_Client would follow this workflow:

1. **Initial Sync:**
    ○ SELECT the folder.
    ○ Store the HIGHESTMODSEQ returned by the server (e.g., 10050).
    ○ Fetch and store all messages.
2. **Subsequent Sync (The Optimization):**
    ○ Instead of a standard SELECT, issue:
       $client->openMailbox($mailbox, Horde_Imap_Client::OPEN_READWRITE,
       array('qresync' => $last_known_modseq));
    ○ The library handles the protocol negotiation.
    ○ The server responds with a list of **vanished** (deleted) UIDs and **fetched** (changed)
       flags _only_ for messages with a MODSEQ higher than 10050.
    ○ The application updates only these specific rows in the database, reducing
       processing time from seconds (or minutes) to milliseconds.^24

This logic is fundamental to Thunderbird's ability to handle massive mailboxes efficiently and
is fully supported by the Horde library, making it the ideal choice for the PHP translation.^30

#### Works cited

#### 1. thunderbird/thunderbird-desktop: This repository is for testing migration of

#### hg->git. It is not (yet) an official repository for Thunderbird. - GitHub, accessed

#### February 6, 2026, https://github.com/thunderbird/thunderbird-desktop

#### 2. mozilla/releases-comm-central: EXPERIMENTAL - GitHub, accessed February 6,

#### 2026, https://github.com/mozilla/releases-comm-central

#### 3. Where is the actual source code? It seems like the website is set up to avoid you

#### browsing it. : r/Thunderbird - Reddit, accessed February 6, 2026,


#### https://www.reddit.com/r/Thunderbird/comments/1pny804/where_is_the_actual_s

#### ource_code_it_seems_like_the/

#### 4. Getting Started Contributing | Thunderbird, accessed February 6, 2026,

#### https://developer.thunderbird.net/thunderbird-development/getting-started

#### 5. thunderbird repositories - GitHub, accessed February 6, 2026,

#### https://github.com/orgs/thunderbird/repositories

#### 6. Mozilla Thunderbird - GitHub, accessed February 6, 2026,

#### https://github.com/thunderbird

#### 7. Thunderbird for Android – Open Source Email App for Android (fka K-9 Mail) -

#### GitHub, accessed February 6, 2026,

#### https://github.com/thunderbird/thunderbird-android

#### 8. Support for the 'CLIENTID' SMTP/IMAP command in Thunderbird -

#### Bugzilla@Mozilla, accessed February 6, 2026,

#### https://bugzilla.mozilla.org/show_bug.cgi?id=

#### 9. Thunderbird doesn't check my Fastmail account automatically for new mail

#### (actually, not really Fastmail specific) - Bugzilla@Mozilla, accessed February 6,

#### 2026, https://bugzilla.mozilla.org/show_bug.cgi?id=

#### 10. Bug #962631 “Thunderbird 11 imap connections hang” - Launchpad Bugs,

#### accessed February 6, 2026, https://bugs.launchpad.net/bugs/

#### 11. 1428097 - Slow opening large gmail IMAP folder, even when there are no new

#### messages in that folder. (Slow Fetch?) - Bugzilla@Mozilla, accessed February 6,

#### 2026, https://bugzilla.mozilla.org/show_bug.cgi?id=

#### 12. How can I tell when the Offline message download complete? | Thunderbird

#### Support Forum, accessed February 6, 2026,

#### https://support.mozilla.org/gl/questions/

#### 13. With imap_tools (or imaplib), how do I sync imap changes instead of polling by

#### repeatedly fetching the entire imap database? - Stack Overflow, accessed

#### February 6, 2026,

#### https://stackoverflow.com/questions/66025391/with-imap-tools-or-imaplib-how-

#### do-i-sync-imap-changes-instead-of-polling-by-r

#### 14. IMAP Synchronization | Thunderbird Help - Mozilla Support, accessed February 6,

#### 2026, https://support.mozilla.org/en-US/kb/imap-synchronization

#### 15. Just moved from POP3 to IMAP, but IMAP is very laggy and slow? Possible fix? -

#### Reddit, accessed February 6, 2026,

#### https://www.reddit.com/r/Thunderbird/comments/1093tgh/just_moved_from_pop

#### 3_to_imap_but_imap_is_very/

#### 16. 1580480 - IMAP fetch chunk size is always 65536 bytes - Bugzilla@Mozilla,

#### accessed February 6, 2026, https://bugzilla.mozilla.org/show_bug.cgi?id=

#### 17. Do you support the IMAP IDLE feature? - hosting.com, accessed February 6,

#### 2026, https://kb.hosting.com/docs/do-you-support-the-imap-idle-feature

#### 18. 436615 - Better Faster IMAP: Preemptive/Automatic message download feature,

#### accessed February 6, 2026, https://bugzilla.mozilla.org/show_bug.cgi?id=

#### 19. 1776823 - Thunderbird offline autosynchronization not happening -

#### Bugzilla@Mozilla, accessed February 6, 2026,

#### https://bugzilla.mozilla.org/show_bug.cgi?id=


#### 20. How to download only recent mails in Thunderbird? - Ask Ubuntu, accessed

#### February 6, 2026,

#### https://askubuntu.com/questions/143014/how-to-download-only-recent-mails-in

#### -thunderbird

#### 21. Better Faster IMAP: Pseudo-offline Delete and Move support - Bugzilla@Mozilla,

#### accessed February 6, 2026, https://bugzilla.mozilla.org/show_bug.cgi?id=

#### 22. 1747311 - Add support for imap extension QRESYNC and improvements for

#### CONDSTORE (RFC 7162) - Bugzilla@Mozilla, accessed February 6, 2026,

#### https://bugzilla.mozilla.org/show_bug.cgi?id=

#### 23. IMAP QRESYNC Extension (qresync) - Datatracker - IETF, accessed February 6,

#### 2026, https://datatracker.ietf.org/wg/qresync/about/

#### 24. Support for the IMAP CONDSTORE/QRESYNC extensions · Issue #1155 - GitHub,

#### accessed February 6, 2026, https://github.com/neomutt/neomutt/issues/

#### 25. RFC 7162 - IMAP Extensions: Quick Flag Changes Resynchronization

#### (CONDSTORE) and Quick Mailbox Resynchronization (QRESYNC) - IETF

#### Datatracker, accessed February 6, 2026, https://datatracker.ietf.org/doc/rfc7162/

#### 26. IMAP IDLE many accounts in PHP - Stack Overflow, accessed February 6, 2026,

#### https://stackoverflow.com/questions/25402265/imap-idle-many-accounts-in-php

#### 27. reactphp vs swoole vs ratchet vs amp. which do you prefer and why? : r/PHP -

#### Reddit, accessed February 6, 2026,

#### https://www.reddit.com/r/PHP/comments/bmrfiq/reactphp_vs_swoole_vs_ratchet

#### _vs_amp_which_do_you/

#### 28. Dispatch events from separate php processes - Laracasts, accessed February 6,

#### 2026,

#### https://laracasts.com/discuss/channels/code-review/dispatch-events-from-separ

#### ate-php-processes

#### 29. Horde/Imap_Client: PHP IMAP Client Library, accessed February 6, 2026,

#### https://dev.horde.org/imap_client/

#### 30. Horde/Imap_Client: PHP IMAP Client Library, accessed February 6, 2026,

#### https://dev.horde.org/imap_client/features.php

#### 31. Will email(s) be restored by the IMAP sync process after removal of the

#### corresponding message file of an IMAP account from a Thunderbird profile? -

#### Super User, accessed February 6, 2026,

#### https://superuser.com/questions/1772843/will-emails-be-restored-by-the-imap-s

#### ync-process-after-removal-of-the-correspo

#### 32. Get saved search data out of Thunderbird and into plain-text file - PerlMonks,

#### accessed February 6, 2026, https://www.perlmonks.org/?node_id=

#### 33. Why Thunderbird slows down with huge mailboxes - Peter Martin - WoodCentral,

#### accessed February 6, 2026,

#### https://www.woodcentral.com/-/peter/why-thunderbird-slows-down-with-huge-

#### mailboxes/

#### 34. RFC 5162: IMAP4 Extensions for Quick Mailbox Resynchronization, accessed

#### February 6, 2026, https://www.rfc-editor.org/rfc/rfc5162.html

#### 35. Email Folder Sync Issues 2026: Why Server Changes Are Breaking Your Workflow

- Mailbird, accessed February 6, 2026,


#### https://www.getmailbird.com/email-folder-sync-issues-server-side-changes/

#### 36. Thunderbird only downloading for offline use one message, not the many 100s in

#### the folder, accessed February 6, 2026,

#### https://superuser.com/questions/405610/thunderbird-only-downloading-for-offli

#### ne-use-one-message-not-the-many-100s-in-t


