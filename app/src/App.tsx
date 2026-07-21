import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import type { ReactNode } from "react";
import { SessionProvider, useSession } from "./lib/session";
import { StoreProvider } from "./lib/store";
import { AppShell } from "./components/chrome";
import { LoginScreen } from "./screens/LoginScreen";
import { QueueScreen } from "./screens/QueueScreen";
import { TicketScreen } from "./screens/TicketScreen";
import { ClientsScreen } from "./screens/ClientsScreen";
import { ClientScreen } from "./screens/ClientScreen";
import { TimeScreen } from "./screens/TimeScreen";

function RequireSession({ children }: { children: ReactNode }) {
  const { user } = useSession();
  if (!user) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

function LoginGate() {
  const { user } = useSession();
  if (user) return <Navigate to="/" replace />;
  return <LoginScreen />;
}

export default function App() {
  return (
    <SessionProvider>
      <StoreProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginGate />} />
            <Route
              element={
                <RequireSession>
                  <AppShell />
                </RequireSession>
              }
            >
              <Route path="/" element={<QueueScreen />} />
              <Route path="/tickets/:number" element={<TicketScreen />} />
              <Route path="/clients" element={<ClientsScreen />} />
              <Route path="/clients/:id" element={<ClientScreen />} />
              <Route path="/time" element={<TimeScreen />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </StoreProvider>
    </SessionProvider>
  );
}
