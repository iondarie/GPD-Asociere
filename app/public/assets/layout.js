/* Global shell adjustments kept separate from application behaviour. */
document.addEventListener('DOMContentLoaded', () => {
  const account = document.querySelector('.sidebar .account');
  const header = document.querySelector('.page-head');
  if (!account || !header) return;

  account.classList.add('header-account');
  header.append(account);
});
