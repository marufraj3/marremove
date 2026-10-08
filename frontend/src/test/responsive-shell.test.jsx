import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AppShell } from '../components/AppShell.jsx';

describe('responsive admin shell', () => {
  it('provides a toggleable mobile navigation with all admin routes', async () => {
    const user = userEvent.setup();
    render(<AppShell activePage="dashboard"><h1>Dashboard</h1></AppShell>);
    const menu = screen.getByRole('button', { name: 'Open navigation' });
    await user.click(menu);
    expect(screen.getAllByRole('button', { name: 'Close navigation' })[0]).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getAllByRole('navigation', { name: 'Admin navigation' })).toHaveLength(2);
    expect(screen.getAllByRole('link', { name: 'Facebook Pages' })).toHaveLength(2);
    expect(screen.getAllByRole('link', { name: 'System Settings' })).toHaveLength(2);
  });
});
