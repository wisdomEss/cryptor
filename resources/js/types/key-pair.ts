export interface KeyPair {
    id: number;
    user_id: number;
    key_name: string;
    public_key: string;
    private_key?: string;
    algorithm_used: string;
    key_size: number;
    is_default: boolean;
    is_active: boolean;
    is_revoked?: boolean;
    valid_from?: string; // ou Date si tu pars sur des Date objects
    valid_to?: string;
    certificate_data?: string;
    certificate_issuer?: string;
}
