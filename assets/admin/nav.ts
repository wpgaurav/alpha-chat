import { createContext, useContext } from '@wordpress/element';

export type AdminNav = {
	goTo: ( tab: string ) => void;
};

export const AdminNavContext = createContext< AdminNav >( {
	goTo: () => undefined,
} );

export function useAdminNav(): AdminNav {
	return useContext( AdminNavContext );
}
