<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];

        $builder
            ->add('fullName', TextType::class, [
                'label'       => 'Nom complet',
                'constraints' => [new NotBlank(['message' => 'Le nom est obligatoire'])],
                'attr'        => ['placeholder' => 'Ex: Wided Nada'],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'Adresse email',
                'constraints' => [
                    new NotBlank(['message' => 'Email obligatoire']),
                    new Email(['message' => 'Email invalide']),
                ],
                'attr' => ['placeholder' => 'exemple@orange.tn'],
            ])
            ->add('role', ChoiceType::class, [
                'label'   => 'Rôle',
                'choices' => [
                    'Administrateur'    => 'admin',
                    'Super Utilisateur' => 'superuser',
                    'Support FO'        => 'support_fo',
                    'Support FH'        => 'support_fh',
                    'Ingénierie FH'     => 'ingenierie_fh',
                    'Ingénierie IP'     => 'ingenierie_ip',
                    'Département GC'    => 'gc',
                    'Déploiement'       => 'deploiement',
                    'Contrôleur'        => 'controleur', // Nouveau rôle ajouté
                ],
                'attr' => ['class' => 'form-control'],
            ]);

        if (!$isEdit) {
            $builder->add('plainPassword', PasswordType::class, [
                'label'       => 'Mot de passe',
                'mapped'      => false,
                'constraints' => [
                    new NotBlank(['message' => 'Mot de passe obligatoire']),
                    new Length([
                        'min'        => 6,
                        'minMessage' => 'Minimum {{ limit }} caractères',
                    ]),
                ],
                'attr' => ['placeholder' => '••••••••'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit'    => false,
        ]);
    }
}