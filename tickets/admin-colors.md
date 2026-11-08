# wp-admin colors: Ocean by default, the agent chat in the user's scheme

**Milestone:** design follow-up (M3/M4) · **Spec:** documentation/mvp-spec.md decision 15, documentation/ai-engine.md ("Where the agent appears") · **Status:** done (2026-11-08; the browser check is the maintainer's)

Asked for by the maintainer on 2026-11-08:

> The colors of the chat of the agent should respect the colors selected by the user on their profile. The color of the button and borders and all looks good. But the color of the bubble chat of the messages sent by the user, I would like it to be the secondary color of the selected theme and not the primary. With this I refer to the second color shown in the palette.
>
> Make the Ocean theme the default wp-admin theme for all our users.

## Deliverables
- The user's own messages in the agent chat (the editor bubble and the main site's agent page) use the second color of their admin color scheme's palette, the second swatch in Profile → Admin Color Scheme. Buttons and borders keep the scheme's accent.
- Ocean is every user's admin color scheme by default. Users can still choose another.

## Acceptance criteria
- A user on Ocean sees their messages in `#39535a`; on any other scheme, in that scheme's second color.
- A new user, and a user with no scheme stored, gets Ocean.
- Existing users on WordPress's default (Modern) are on Ocean after the deploy; users who chose another scheme keep it.

## Results (2026-11-08)
**What "primary" was.** In WordPress 7.1 a scheme has a palette of three or four colors, shown in the profile, and an accent, `--wp-admin-theme-color`, set by core's `admin-schemes.css`. The chat used the accent for everything. For Ocean the accent is the palette's third color (`#567958`, green), and the second is `#39535a`. For Modern, WordPress's default since 7.0, both are `#3858e9`.

**Built** in `app/web/mu-plugins/mody-platform/src/Admin/AdminColors.php`:
- **The chat color:**
  - `second_color()` reads the user's scheme from core's registry. An unknown scheme counts as Ocean.
  - `add_agent_chat_css()` puts it on the `mody-agent-chat` style as `--mody-agent-user-message`. Both places that load the chat call it: `EditorBubble::enqueue()` and `ChatPage::enqueue()`.
  - `assets/agent-chat.css` reads that variable for `.mody-agent__message--user`, falling back to the accent.
- **Ocean by default:**
  - `insert_user_meta`: a new user gets Ocean unless the code creating them names a scheme.
  - `get_user_option_admin_color`: Ocean for a user with no scheme stored.
  - `migrate()` on `init`: once per network (network option `mody_admin_colors_version`), users on Modern or with an empty value move to Ocean. Users who chose Modern on purpose cannot be told apart and move too; they can choose it again.
- **Data touched by the deploy:** production has one user, on Modern. Locally, two of three users were moved when the code first ran.

**Test:** `scripts/admin-colors-test.php`, 26 checks, no model call:
- the defaults, a chosen scheme kept, a later change kept, the move;
- each core scheme's second color as the user color, and white text on it at 4.5:1 or more (Light is the lowest at 5.4:1);
- over HTTP:
  - the Site Editor on a member site, for a member on Ocean and one on Sunrise: the body class and the bubble's color;
  - the agent page on the main site for a super admin.

**Every suite passes** (17, offline).

**Not checked by the tests:** how it looks.
- Open the bubble in the Site Editor and send a message.
- Change the scheme in your profile, reload, and the message color follows.
